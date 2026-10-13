<?php

namespace App\Repository;

use App\Entity\Passage;
use App\Entity\Soiree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Passage>
 */
class PassageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Passage::class);
    }

    /**
     * The soirée's passages by start time, with their bookings and artists,
     * in one query.
     *
     * @return list<Passage>
     */
    public function findTimetable(Soiree $soiree): array
    {
        return $this->createQueryBuilder('p')
            ->select('p', 'b', 'a', 'lb')
            ->leftJoin('p.bookings', 'b')
            ->leftJoin('b.artiste', 'a')
            // Inverse side of a one-to-one: Doctrine would load it booking by booking.
            ->leftJoin('b.ligneBudget', 'lb')
            ->where('p.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->orderBy('p.debut')
            ->addOrderBy('b.id')
            ->getQuery()
            ->getResult();
    }
}
