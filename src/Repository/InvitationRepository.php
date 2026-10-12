<?php

namespace App\Repository;

use App\Entity\Collective;
use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /**
     * Pending invitations of the active collective, newest first.
     *
     * @return list<Invitation>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The not-yet-accepted invitation to $collective for $email, if any.
     */
    public function findOpenForEmail(Collective $collective, string $email): ?Invitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.collective = :collective')
            ->andWhere('i.email = :email')
            ->andWhere('i.acceptedAt IS NULL')
            ->setParameter('collective', $collective)
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Looks an invitation up by its plain token, with its collective and inviter
     * fetched in the same query. Subject to the "collective" filter: invitees
     * have no active collective yet, so call it with the filter disabled.
     */
    public function findOneByToken(string $token): ?Invitation
    {
        return $this->createQueryBuilder('i')
            ->addSelect('c', 'u')
            ->join('i.collective', 'c')
            ->leftJoin('i.invitedBy', 'u')
            ->andWhere('i.tokenHash = :hash')
            ->setParameter('hash', Invitation::hashToken($token))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
