<?php

namespace App\Repository;

use App\Entity\GuestList;
use App\Entity\Soiree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestList>
 */
class GuestListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestList::class);
    }

    /**
     * Lists of $soiree with their owners and guests.
     *
     * @return list<GuestList>
     */
    public function findPourSoiree(Soiree $soiree): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('b', 'a', 'm', 'i')
            ->leftJoin('g.booking', 'b')
            ->leftJoin('b.artiste', 'a')
            ->leftJoin('g.membre', 'm')
            ->leftJoin('g.invites', 'i')
            ->where('g.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->orderBy('g.id')
            ->getQuery()
            ->getResult();
    }
}
