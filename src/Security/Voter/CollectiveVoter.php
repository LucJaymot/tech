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
 * What a user may do in a collective, from their role in it.
 *
 * @extends Voter<string, Collective>
 */
final class CollectiveVoter extends Voter
{
    /** Invite members and manage invitations. Admins only. */
    public const string MANAGE_MEMBERS = 'COLLECTIVE_MANAGE_MEMBERS';

    /** Create and change the collective's content. Admins and members. */
    public const string EDIT = 'COLLECTIVE_EDIT';

    /** See the collective's budget. Every role, readers included. */
    public const string VIEW_BUDGET = 'COLLECTIVE_VIEW_BUDGET';

    private const array ALLOWED_ROLES = [
        self::MANAGE_MEMBERS => [MembershipRole::Admin],
        self::EDIT => [MembershipRole::Admin, MembershipRole::Member],
        self::VIEW_BUDGET => [MembershipRole::Admin, MembershipRole::Member, MembershipRole::Reader],
    ];

    public function __construct(
        private readonly MembershipRepository $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ALLOWED_ROLES[$attribute]) && $subject instanceof Collective;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            $vote?->addReason('Not logged in.');

            return false;
        }

        // Filtered to the active collective: any other collective is denied.
        $membership = $this->memberships->findOneBy(['user' => $user, 'collective' => $subject]);
        if (null === $membership) {
            $vote?->addReason('Not a member of the active collective.');

            return false;
        }

        if (!\in_array($membership->getRole(), self::ALLOWED_ROLES[$attribute], true)) {
            $vote?->addReason(\sprintf('The "%s" role does not allow this.', $membership->getRole()->value));

            return false;
        }

        return true;
    }
}
