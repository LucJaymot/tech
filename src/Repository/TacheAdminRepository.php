<?php

namespace App\Repository;

use App\Entity\Collective;
use App\Entity\Soiree;
use App\Entity\TacheAdmin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TacheAdmin>
 */
class TacheAdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TacheAdmin::class);
    }

    /**
     * @return list<TacheAdmin> by due date
     */
    public function findPourSoiree(Soiree $soiree): array
    {
        return $this->findBy(['soiree' => $soiree], ['echeance' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Open tasks of $collective, overdue ones first as they are due earliest.
     *
     * @return list<TacheAdmin> with their soirées
     */
    public function findOuvertesDuCollectif(Collective $collective): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->join('t.soiree', 's')
            ->where('t.collective = :collective')
            ->andWhere('t.faiteLe IS NULL')
            ->setParameter('collective', $collective)
            ->orderBy('t.echeance', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tasks due after the soirée (e.g. the Guso declaration) not done yet.
     *
     * @return list<TacheAdmin> by due date
     */
    public function findApresSoireeOuvertes(Soiree $soiree): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.soiree = :soiree')
            ->andWhere('t.faiteLe IS NULL')
            ->andWhere('t.echeance > :date')
            ->setParameter('soiree', $soiree)
            ->setParameter('date', $soiree->getDate(), Types::DATE_IMMUTABLE)
            ->orderBy('t.echeance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tasks not done yet whose due date is one of $echeances.
     *
     * @param list<\DateTimeImmutable> $echeances
     *
     * @return list<TacheAdmin>
     */
    public function findAFaireLe(array $echeances): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->join('t.soiree', 's')
            ->where('t.faiteLe IS NULL')
            ->andWhere('t.echeance IN (:echeances)')
            ->setParameter('echeances', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $echeances))
            ->orderBy('t.echeance', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tasks not done yet that are due by $jusquau, overdue ones included.
     *
     * @return list<TacheAdmin> by due date
     */
    public function findAFaireAvant(\DateTimeImmutable $jusquau): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->join('t.soiree', 's')
            ->where('t.faiteLe IS NULL')
            ->andWhere('t.echeance <= :jusquau')
            ->setParameter('jusquau', $jusquau, Types::DATE_IMMUTABLE)
            ->orderBy('t.echeance', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
