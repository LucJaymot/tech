<?php

namespace App\Repository;

use App\Collective\CollectiveChoice;
use App\Entity\Collective;
use App\Entity\Membership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /**
     * Memberships of the active collective, with their users, sorted by role then name.
     *
     * @return list<Membership>
     */
    public function findForActiveCollective(): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->orderBy('m.role')
            ->addOrderBy('u.displayName')
            ->getQuery()
            ->getResult();
    }

    /**
     * Subject to the "collective" filter: call it with the filter disabled.
     */
    public function isMember(User $user, Collective|int $collective): bool
    {
        return (bool) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.user = :user')
            ->andWhere('m.collective = :collective')
            ->setParameter('user', $user)
            ->setParameter('collective', $collective)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Subject to the "collective" filter: call it with the filter disabled.
     *
     * @return list<CollectiveChoice>
     */
    public function findCollectiveChoicesForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->select(\sprintf('NEW %s(c.id, c.name, c.slug, m.role)', CollectiveChoice::class))
            ->join('m.collective', 'c')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.joinedAt')
            ->addOrderBy('m.id')
            ->getQuery()
            ->getResult();
    }
}
