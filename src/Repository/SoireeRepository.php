<?php

namespace App\Repository;

use App\Entity\Collective;
use App\Entity\Soiree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Soiree>
 */
class SoireeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Soiree::class);
    }

    /**
     * Soirées of $collective in calendar year $annee whose boolean $propriete is set.
     * $propriete comes from SeuilAnnuel::CRITERES, never from user input.
     */
    public function compterDansLAnnee(Collective $collective, int $annee, string $propriete): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.collective = :collective')
            ->andWhere(\sprintf('s.%s = true', $propriete))
            ->andWhere('s.date BETWEEN :debut AND :fin')
            ->setParameter('collective', $collective)
            ->setParameter('debut', new \DateTimeImmutable($annee.'-01-01'), Types::DATE_IMMUTABLE)
            ->setParameter('fin', new \DateTimeImmutable($annee.'-12-31'), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Soiree> on or after $depuis
     */
    public function findAPartirDu(\DateTimeImmutable $depuis): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.date >= :depuis')
            ->setParameter('depuis', $depuis, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }
}
