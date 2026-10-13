<?php

namespace App\Soiree;

use App\Entity\Booking;
use App\Entity\Passage;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Obligations\Obligations;
use App\Repository\PassageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a soirée's feuille de route in a fixed number of queries, whatever
 * the size of the line-up or of the team.
 */
final class FeuilleDeRoute
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PassageRepository $passages,
        private readonly Obligations $obligations,
    ) {
    }

    public function pour(Soiree $soiree): VueFeuilleDeRoute
    {
        $passages = $this->passages->findTimetable($soiree);

        /** @var list<Poste> $postes */
        $postes = $this->entityManager->createQueryBuilder()
            ->select('po', 'c', 'af', 'be')
            ->from(Poste::class, 'po')
            ->leftJoin('po.creneaux', 'c')
            ->leftJoin('c.affectations', 'af')
            ->leftJoin('af.benevole', 'be')
            ->where('po.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->orderBy('po.id')
            ->addOrderBy('c.debut')
            ->addOrderBy('be.nom')
            ->getQuery()
            ->getResult();

        $artistes = [];
        foreach ($passages as $passage) {
            foreach ($passage->getBookings() as $booking) {
                $artiste = $booking->getArtiste();
                $artistes[$artiste->getNom()] = ['nom' => $artiste->getNom(), 'telephone' => $artiste->getTelephone(), 'email' => $artiste->getEmail()];
            }
        }

        [$ouverture, $fermeture] = [$soiree->getOuverture(), $soiree->getFermeture()];
        $lieu = $soiree->getLieu();

        return new VueFeuilleDeRoute(
            id: (int) $soiree->getId(),
            soiree: $soiree->getNom(),
            ouverture: $ouverture,
            fermeture: $fermeture,
            jauge: $soiree->getJauge(),
            lieu: $lieu?->getNom(),
            adresse: $lieu?->getAdresse(),
            acces: $lieu?->getAcces(),
            timetable: array_map(static fn (Passage $p): array => [
                'debut' => $p->getDebut(),
                'fin' => $p->getFin(),
                'artistes' => array_values($p->getBookings()->map(static fn (Booking $b): string => $b->getArtiste()->getNom())->toArray()),
            ], $passages),
            equipe: array_map(static fn (Poste $poste): array => [
                'poste' => $poste->getIntitule(),
                'creneaux' => array_values($poste->getCreneaux()->map(static fn ($c): array => [
                    'debut' => $c->getDebut(),
                    'fin' => $c->getFin(),
                    'benevoles' => array_values($c->getAffectations()->map(static fn ($a): array => ['nom' => $a->getBenevole()->getNom(), 'telephone' => $a->getBenevole()->getTelephone()])->toArray()),
                ])->toArray()),
            ], $postes),
            artistes: array_values($artistes),
            checks: $this->obligations->checksPour($soiree),
        );
    }
}
