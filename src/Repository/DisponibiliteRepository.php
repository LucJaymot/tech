<?php

namespace App\Repository;

use App\Entity\Disponibilite;
use App\Entity\Soiree;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Disponibilite>
 */
class DisponibiliteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Disponibilite::class);
    }

    /**
     * Ids of the available volunteers, by créneau id.
     *
     * @return array<int, list<int>>
     */
    public function benevolesParCreneau(Soiree $soiree): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.creneau) AS creneau', 'IDENTITY(d.benevole) AS benevole')
            ->join('d.creneau', 'c')
            ->where('c.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->getQuery()
            ->getArrayResult();

        $parCreneau = [];
        foreach ($rows as $row) {
            $parCreneau[(int) $row['creneau']][] = (int) $row['benevole'];
        }

        return $parCreneau;
    }
}
