<?php

namespace App\Budget;

use App\Entity\Justificatif;
use App\Entity\LigneBudget;
use App\Entity\User;
use App\Storage\FichierRefuse;
use App\Storage\Televersement;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Stores budget receipts in the "justificatifs" Flysystem storage and keeps
 * their Justificatif records in sync.
 */
final class Justificatifs
{
    public const string TAILLE_MAX = '10M';

    /** Scans and phone photos of receipts and invoices. */
    public const array TYPES_AUTORISES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
    ];

    public function __construct(
        #[Target('justificatifs.storage')]
        private readonly FilesystemOperator $storage,
        private readonly Televersement $televersement,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FichierRefuse when the file is too large or of a type not allowed
     */
    public function ajouter(LigneBudget $ligne, File $fichier, ?User $ajoutePar): Justificatif
    {
        $stocke = $this->televersement->stocker(
            $this->storage,
            $fichier,
            \sprintf('%d/%d/%d', $ligne->getCollective()->getId(), $ligne->getSoiree()->getId(), $ligne->getId()),
            self::TAILLE_MAX,
            self::TYPES_AUTORISES,
        );

        $justificatif = new Justificatif($ligne, $stocke->nomOriginal, $stocke->chemin, $stocke->typeMime, $stocke->taille, $ajoutePar);
        try {
            $this->entityManager->persist($justificatif);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->storage->delete($stocke->chemin);

            throw $e;
        }

        return $justificatif;
    }

    /**
     * @return resource
     */
    public function lire(Justificatif $justificatif)
    {
        return $this->storage->readStream($justificatif->getChemin());
    }

    public function supprimer(Justificatif $justificatif): void
    {
        $justificatif->getLigneBudget()->getJustificatifs()->removeElement($justificatif);
        $this->entityManager->remove($justificatif);
        $this->entityManager->flush();

        $this->storage->delete($justificatif->getChemin());
    }
}
