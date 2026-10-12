<?php

namespace App\Soiree;

use App\Entity\Creneau;
use App\Entity\LigneBudget;
use App\Entity\Poste;
use App\Entity\Soiree;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a new soirée from an existing one.
 *
 * Copies the lieu, opening hours, jauge, buvette, artistes payés, postes with
 * their créneaux (moved to the new date) and forecast budget (without fee
 * lines, which follow the bookings). Never copies dates (the new one is
 * given), actual amounts, assignments, availabilities or check-ins, nor
 * attachments. The copy starts in preparation.
 */
final class SoireeDuplicator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function duplicate(Soiree $source, \DateTimeImmutable $date, ?string $nom = null): Soiree
    {
        $copy = new Soiree($source->getCollective(), $nom ?? $source->getNom(), $date, $source->getOuverture(), $source->getFermeture());
        $copy->setLieu($source->getLieu())
            ->setJauge($source->getJauge())
            ->setBuvette($source->hasBuvette())
            ->setArtistesPayes($source->hasArtistesPayes());

        // Field by field, so that assignments and availabilities are never carried
        // over; créneaux move with the date, keeping their hours.
        $decalage = $source->getDate()->diff($copy->getDate());
        foreach ($source->getPostes() as $poste) {
            $nouveau = new Poste($copy, $poste->getIntitule());
            foreach ($poste->getCreneaux() as $creneau) {
                new Creneau($nouveau, $creneau->getDebut()->add($decalage), $creneau->getFin()->add($decalage), $creneau->getNombreRequis());
            }
        }
        // Forecast amounts only; fee lines follow bookings, which are not copied.
        foreach ($source->getLignesBudget() as $ligne) {
            if (!$ligne->estGeneree()) {
                new LigneBudget($copy, $ligne->getType(), $ligne->getCategorie(), $ligne->getLibelle(), $ligne->getMontantPrevu());
            }
        }

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }
}
