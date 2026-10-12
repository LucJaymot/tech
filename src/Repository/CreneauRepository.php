<?php

namespace App\Repository;

use App\Entity\Creneau;
use App\Entity\Soiree;
use App\Planning\Trou;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Creneau>
 */
class CreneauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Creneau::class);
    }

    /**
     * Créneaux of $soiree with fewer assignments than required, in time order.
     * Computed by the database: required people versus counted assignments.
     *
     * @return list<Trou>
     */
    public function findTrous(Soiree $soiree): array
    {
        /** @var list<array{0: Creneau, affectes: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->addSelect('p', 'COUNT(a.id) AS affectes')
            ->join('c.poste', 'p')
            ->leftJoin('c.affectations', 'a')
            ->where('c.soiree = :soiree')
            ->groupBy('c.id', 'p.id')
            ->having('COUNT(a.id) < c.nombreRequis')
            ->orderBy('c.debut')
            ->addOrderBy('p.intitule')
            ->setParameter('soiree', $soiree)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): Trou => new Trou($row[0], $row[0]->getNombreRequis(), (int) $row['affectes']),
            $rows,
        );
    }
}
