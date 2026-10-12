<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Collective\InvitationManager;
use App\Entity\User;
use App\Form\InvitationType;
use App\Repository\InvitationRepository;
use App\Security\Voter\CollectiveVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCollective $activeCollective,
        private readonly InvitationManager $invitationManager,
    ) {
    }

    #[Route('/collectif/inviter', name: 'app_invitation_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        #[CurrentUser] User $user,
        Request $request,
        #[Target('invitation')] RateLimiterFactoryInterface $invitationLimiter,
    ): Response {
        $collective = $this->activeCollective->get() ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CollectiveVoter::MANAGE_MEMBERS, $collective);

        $form = $this->createForm(InvitationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim($form->get('email')->getData()));

            if ($this->invitationManager->isMember($collective, $email)) {
                $form->get('email')->addError(new FormError('Cette personne est déjà membre du collectif.'));
            } elseif (!$invitationLimiter->create((string) $user->getId())->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop d\'invitations envoyées. Réessayez plus tard.');

                return $this->render('invitation/new.html.twig', ['form' => $form, 'collective' => $collective], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            } else {
                $this->invitationManager->invite($collective, $email, $form->get('role')->getData(), $user);
                $this->addFlash('success', \sprintf('Invitation envoyée à %s.', $email));

                return $this->redirectToRoute('app_collective');
            }
        }

        return $this->render('invitation/new.html.twig', ['form' => $form, 'collective' => $collective]);
    }

    #[Route('/collectif/invitations/{id}/annuler', name: 'app_invitation_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('cancel-invitation')]
    public function cancel(int $id, InvitationRepository $invitations, EntityManagerInterface $entityManager): Response
    {
        // Filtered: invitations of other collectives are not found.
        $invitation = $invitations->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CollectiveVoter::MANAGE_MEMBERS, $invitation->getCollective());

        $entityManager->remove($invitation);
        $entityManager->flush();
        $this->addFlash('success', \sprintf('Invitation de %s annulée.', $invitation->getEmail()));

        return $this->redirectToRoute('app_collective');
    }

    /**
     * GET shows the invitation; the "Rejoindre" button POSTs back here.
     */
    #[Route('/invitation/{token}', name: 'app_invitation', methods: ['GET', 'POST'])]
    public function accept(string $token, Request $request, InvitationRepository $invitations, Security $security): Response
    {
        $invitation = $this->activeCollective->withoutFilter(fn () => $invitations->findOneByToken($token));
        if (null === $invitation || !$invitation->isPending()) {
            return $this->render('invitation/invalid.html.twig', response: new Response(status: null === $invitation ? Response::HTTP_NOT_FOUND : Response::HTTP_GONE));
        }

        $current = $this->getUser();
        $otherAccount = $current instanceof User && $current->getEmail() !== $invitation->getEmail();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('accept-invitation', $request->request->getString('_token')) || $otherAccount) {
                throw $this->createAccessDeniedException();
            }

            $user = $current instanceof User ? $current : $this->invitationManager->findOrCreateInvitee($invitation);
            if (!$current instanceof User) {
                $security->login($user, 'login_link', 'main', [new RememberMeBadge()]);
            }
            $this->invitationManager->accept($invitation, $user);
            $this->activeCollective->switchTo($user, $invitation->getCollective());
            $this->addFlash('success', \sprintf('Bienvenue dans %s !', $invitation->getCollective()->getName()));

            return $this->redirectToRoute('app_collective');
        }

        return $this->render('invitation/show.html.twig', [
            'invitation' => $invitation,
            'other_account' => $otherAccount,
        ]);
    }
}
