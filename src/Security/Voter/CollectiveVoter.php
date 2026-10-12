<?php

namespace App\Security\Voter;

use App\Entity\Collective;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Collective>
 */
final class CollectiveVoter extends Voter
{
    /** Invite members and manage invitations. */
    public const string MANAGE_MEMBERS = 'COLLECTIVE_MANAGE_MEMBERS';

    public function __construct(
        private readonly MembershipRepository $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE_MEMBERS === $attribute && $subject instanceof Collective;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Filtered to the active collective: any other collective is denied.
        $membership = $this->memberships->findOneBy(['user' => $user, 'collective' => $subject]);

        return MembershipRole::Admin === $membership?->getRole();
    }
}
