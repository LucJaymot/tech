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
 * forecast budget. Never copies dates (the new one is given), assignments or
 * check-ins of the postes, nor attachments. The copy starts in preparation.
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
        foreach ($source->getLignesBudget() as $ligne) {
            new LigneBudget($copy, $ligne->getLibelle(), $ligne->getType(), $ligne->getMontantPrevu());
        }

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }
}
