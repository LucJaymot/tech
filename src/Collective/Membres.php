<?php

namespace App\Collective;

use App\Entity\Collective;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes the role of a collective's members or removes them, never leaving
 * the collective without an admin.
 */
final class Membres
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipRepository $memberships,
        private readonly ActiveCollective $activeCollective,
    ) {
    }

    /**
     * A collective with other members that would lose its only admin if $user
     * deleted their account; null when the account can go.
     */
    public function dernierAdminDe(User $user): ?Collective
    {
        // Memberships are filtered to the active collective otherwise.
        return $this->activeCollective->withoutFilter(function () use ($user): ?Collective {
            foreach ($this->memberships->findBy(['user' => $user, 'role' => MembershipRole::Admin]) as $membership) {
                $collective = $membership->getCollective();
                if ($this->memberships->count(['collective' => $collective]) > 1
                    && $this->memberships->countAdmins($collective) <= 1) {
                    return $collective;
                }
            }

            return null;
        });
    }

    /**
     * @return bool false when $membership is the last admin and $role is not admin
     */
    public function changerRole(Membership $membership, MembershipRole $role): bool
    {
        return $this->sansPerdreLeDernierAdmin($membership, $role, static fn () => $membership->setRole($role));
    }

    /**
     * @return bool false when $membership is the last admin
     */
    public function retirer(Membership $membership): bool
    {
        return $this->sansPerdreLeDernierAdmin($membership, null, function () use ($membership): void {
            $membership->getUser()->getMemberships()->removeElement($membership);
            $membership->getCollective()->getMemberships()->removeElement($membership);
            $this->entityManager->remove($membership);
        });
    }

    private function sansPerdreLeDernierAdmin(Membership $membership, ?MembershipRole $role, callable $changement): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($membership, $role, $changement): bool {
            // Locked and re-read: two admins demoting each other at once can't both succeed.
            $this->entityManager->lock($membership->getCollective(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($membership);

            if (MembershipRole::Admin === $membership->getRole() && MembershipRole::Admin !== $role
                && $this->memberships->countAdmins($membership->getCollective()) <= 1) {
                return false;
            }

            $changement();

            return true;
        });
    }
}
