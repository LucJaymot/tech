<?php

namespace App\Collective;

use App\Entity\Collective;
use App\Entity\Invitation;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\InvitationRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InvitationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationRepository $invitations,
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly ActiveCollective $activeCollective,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function isMember(Collective $collective, string $email): bool
    {
        $user = $this->findUser($email);

        return null !== $user && $this->activeCollective->withoutFilter(
            fn (): bool => $this->memberships->isMember($user, $collective),
        );
    }

    /**
     * Invites $email, or re-sends a fresh link if an invitation is already open.
     */
    public function invite(Collective $collective, string $email, MembershipRole $role, User $invitedBy): Invitation
    {
        $invitation = $this->activeCollective->withoutFilter(
            fn (): ?Invitation => $this->invitations->findOpenForEmail($collective, $email),
        ) ?? new Invitation($collective, $email);

        $token = $invitation->issue($role, $invitedBy);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $this->mailer->send((new TemplatedEmail())
            ->to($invitation->getEmail())
            ->subject(\sprintf('Invitation à rejoindre %s', $collective->getName()))
            ->htmlTemplate('emails/invitation.html.twig')
            ->textTemplate('emails/invitation.txt.twig')
            ->context([
                'inviter' => $invitedBy->getDisplayName(),
                'collective' => $collective->getName(),
                'role' => $invitation->getRole()->label(),
                'accept_url' => $this->urlGenerator->generate('app_invitation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                'expires_at' => $invitation->getExpiresAt(),
            ]));

        return $invitation;
    }

    /**
     * The account the invitation is for, created if needed.
     * Following the e-mailed link proves the invitee owns the address.
     */
    public function findOrCreateInvitee(Invitation $invitation): User
    {
        $user = $this->findUser($invitation->getEmail());
        if (null === $user) {
            $user = new User($invitation->getEmail(), self::nameFromEmail($invitation->getEmail()));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        return $user;
    }

    /**
     * Adds $invitee to the collective with the invited role. Call it once the
     * invitee is logged in, so the activity log credits them.
     */
    public function accept(Invitation $invitation, User $invitee): void
    {
        if (!$invitation->isPending()) {
            throw new \LogicException('This invitation is no longer pending.');
        }
        if ($invitee->getEmail() !== $invitation->getEmail()) {
            throw new \LogicException('This invitation is for another address.');
        }

        $collective = $invitation->getCollective();
        $alreadyMember = $this->activeCollective->withoutFilter(
            fn (): bool => $this->memberships->isMember($invitee, $collective),
        );
        if (!$alreadyMember) {
            $this->entityManager->persist(new Membership($invitee, $collective, $invitation->getRole()));
        }

        $invitation->markAccepted();
        $this->entityManager->flush();
    }

    private function findUser(string $email): ?User
    {
        return $this->activeCollective->withoutFilter(
            fn (): ?User => $this->users->findOneBy(['email' => mb_strtolower(trim($email))]),
        );
    }

    /**
     * "marie.durand@example.com" → "Marie Durand", until the user sets their name.
     */
    private static function nameFromEmail(string $email): string
    {
        $local = strstr($email, '@', true) ?: $email;

        return mb_convert_case(trim(preg_replace('/[._+-]+/', ' ', $local) ?? $local), \MB_CASE_TITLE);
    }
}
