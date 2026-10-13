<?php

namespace App\Repository;

use App\Entity\Debriefing;
use App\Entity\Soiree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Debriefing>
 */
class DebriefingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Debriefing::class);
    }

    /**
     * @return list<Debriefing> with their authors, oldest first
     */
    public function findPourSoiree(Soiree $soiree): array
    {
        return $this->createQueryBuilder('d')
            ->addSelect('a')
            ->join('d.auteur', 'a')
            ->where('d.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->orderBy('d.modifieLe', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
