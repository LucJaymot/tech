<?php

namespace App\Security\Voter;

use App\Entity\Soiree;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What a user may do with a soirée, from their role in its collective.
 * The *_CONFIRMER/TERMINER/FAIRE_BILAN attributes guard the "soiree" workflow transitions.
 *
 * @extends Voter<string, Soiree>
 */
final class SoireeVoter extends Voter
{
    public const string VIEW = 'SOIREE_VIEW';
    public const string EDIT = 'SOIREE_EDIT';
    public const string CONFIRMER = 'SOIREE_CONFIRMER';
    public const string TERMINER = 'SOIREE_TERMINER';
    public const string FAIRE_BILAN = 'SOIREE_FAIRE_BILAN';

    private const array ALLOWED_ROLES = [
        self::VIEW => [MembershipRole::Admin, MembershipRole::Member, MembershipRole::Reader],
        self::EDIT => [MembershipRole::Admin, MembershipRole::Member],
        self::CONFIRMER => [MembershipRole::Admin],
        self::TERMINER => [MembershipRole::Admin, MembershipRole::Member],
        self::FAIRE_BILAN => [MembershipRole::Admin],
    ];

    public function __construct(
        private readonly MembershipRepository $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ALLOWED_ROLES[$attribute]) && $subject instanceof Soiree;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            $vote?->addReason('Not logged in.');

            return false;
        }

        // Filtered to the active collective: soirées of other collectives are denied.
        $role = $this->memberships->findRole($user, $subject->getCollective());
        if (null === $role) {
            $vote?->addReason('Not a member of this soirée\'s collective.');

            return false;
        }

        if (!\in_array($role, self::ALLOWED_ROLES[$attribute], true)) {
            $vote?->addReason(\sprintf('The "%s" role does not allow this.', $role->value));

            return false;
        }

        return true;
    }
}
