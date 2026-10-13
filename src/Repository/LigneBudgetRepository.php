<?php

namespace App\Repository;

use App\Bilan\SommesBudget;
use App\Entity\Collective;
use App\Entity\LigneBudget;
use App\Entity\Soiree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LigneBudget>
 */
class LigneBudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LigneBudget::class);
    }

    /**
     * Budget sums of the collective's soirées in calendar year $annee, one row
     * per soirée, line type and category; soirées without lines get one row
     * with a null type.
     *
     * @return list<SommesBudget>
     */
    public function sommesDeLAnnee(Collective $collective, int $annee): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select(\sprintf(
                'NEW %s(s.id, s.nom, s.date, l.type, l.categorie, SUM(l.montantPrevu), SUM(CASE WHEN l.montantReel IS NULL THEN 0 ELSE l.montantPrevu END), SUM(l.montantReel), COUNT(l.id), COUNT(l.montantReel))',
                SommesBudget::class,
            ))
            ->from(Soiree::class, 's')
            ->leftJoin('s.lignesBudget', 'l')
            ->where('s.collective = :collective')
            ->andWhere('s.date BETWEEN :debut AND :fin')
            ->setParameter('collective', $collective)
            ->setParameter('debut', new \DateTimeImmutable($annee.'-01-01'), Types::DATE_IMMUTABLE)
            ->setParameter('fin', new \DateTimeImmutable($annee.'-12-31'), Types::DATE_IMMUTABLE)
            ->groupBy('s.id', 's.nom', 's.date', 'l.type', 'l.categorie')
            ->orderBy('s.date')
            ->addOrderBy('s.id')
            ->getQuery()
            ->getResult();
    }
}
