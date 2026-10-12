<?php

namespace App\Repository;

use App\Entity\Affectation;
use App\Entity\Benevole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Affectation>
 */
class AffectationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Affectation::class);
    }

    /**
     * Saved assignments of $benevole whose créneau shares time with
     * [$debut, $fin[, other than $sauf. Back-to-back créneaux do not overlap.
     *
     * @return list<Affectation>
     */
    public function findChevauchements(Benevole $benevole, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Affectation $sauf = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('c', 'p')
            ->join('a.creneau', 'c')
            ->join('c.poste', 'p')
            ->where('a.benevole = :benevole')
            ->andWhere('c.debut < :fin')
            ->andWhere('c.fin > :debut')
            ->setParameter('benevole', $benevole)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('c.debut');

        if (null !== $sauf?->getId()) {
            $qb->andWhere('a != :sauf')->setParameter('sauf', $sauf);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Affectation>
     */
    public function findPourBenevoleEtSoiree(Benevole $benevole, int $soireeId): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('c', 'p')
            ->join('a.creneau', 'c')
            ->join('c.poste', 'p')
            ->where('a.benevole = :benevole')
            ->andWhere('IDENTITY(c.soiree) = :soiree')
            ->setParameter('benevole', $benevole)
            ->setParameter('soiree', $soireeId)
            ->orderBy('c.debut')
            ->getQuery()
            ->getResult();
    }
}
