<?php

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
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
    public function isMember(User $user, int $collectiveId): bool
    {
        return (bool) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.user = :user')
            ->andWhere('IDENTITY(m.collective) = :collective')
            ->setParameter('user', $user)
            ->setParameter('collective', $collectiveId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Subject to the "collective" filter: call it with the filter disabled.
     *
     * @return list<array{id: int, name: string, slug: string, role: MembershipRole}>
     */
    public function findCollectiveChoicesForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('c.id', 'c.name', 'c.slug', 'm.role')
            ->join('m.collective', 'c')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.joinedAt')
            ->addOrderBy('m.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'role' => $row['role'] instanceof MembershipRole ? $row['role'] : MembershipRole::from($row['role']),
        ], $rows);
    }
}
