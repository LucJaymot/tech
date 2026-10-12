<?php

namespace App\Soiree;

use App\Entity\LigneBudget;
use App\Entity\Poste;
use App\Entity\Soiree;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a new soirée from an existing one.
 *
 * Copies the lieu, opening hours, jauge, buvette, artistes payés, postes and
 * forecast budget (without fee lines, which follow the bookings). Never copies
 * dates (the new one is given), actual amounts, assignments or check-ins of
 * the postes, nor attachments. The copy starts in preparation.
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

        // Field by field, so that what is attached to a poste is never carried over.
        foreach ($source->getPostes() as $poste) {
            new Poste($copy, $poste->getIntitule(), $poste->getDebut(), $poste->getFin(), $poste->getNombrePersonnes());
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
