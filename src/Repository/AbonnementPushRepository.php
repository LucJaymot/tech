<?php

namespace App\Repository;

use App\Entity\AbonnementPush;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbonnementPush>
 */
class AbonnementPushRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbonnementPush::class);
    }

    /**
     * @return list<AbonnementPush> the devices of the user with this e-mail
     */
    public function findPourEmail(string $email): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getResult();
    }
}
