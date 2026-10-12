<?php

namespace App\Security\Voter;

use App\Entity\Booking;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What a user may do with a booking, from their role in its collective.
 * Guards the "booking" workflow transitions.
 *
 * @extends Voter<string, Booking>
 */
final class BookingVoter extends Voter
{
    /** Change the booking, negotiate, confirm, sign the contract. Admins and members. */
    public const string EDIT = 'BOOKING_EDIT';

    /** Declare to the GUSO and mark as paid. Admins only. */
    public const string PAIEMENT = 'BOOKING_PAIEMENT';

    private const array ALLOWED_ROLES = [
        self::EDIT => [MembershipRole::Admin, MembershipRole::Member],
        self::PAIEMENT => [MembershipRole::Admin],
    ];

    public function __construct(
        private readonly MembershipRepository $memberships,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ALLOWED_ROLES[$attribute]) && $subject instanceof Booking;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            $vote?->addReason('Not logged in.');

            return false;
        }

        // Filtered to the active collective: bookings of other collectives are denied.
        $role = $this->memberships->findRole($user, $subject->getCollective());
        if (null === $role) {
            $vote?->addReason('Not a member of this booking\'s collective.');

            return false;
        }

        if (!\in_array($role, self::ALLOWED_ROLES[$attribute], true)) {
            $vote?->addReason(\sprintf('The "%s" role does not allow this.', $role->value));

            return false;
        }

        return true;
    }
}
