<?php

namespace App\Soiree;

use App\Entity\PieceJointe;
use App\Entity\Soiree;
use App\Entity\User;
use App\Storage\FichierRefuse;
use App\Storage\Televersement;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Stores soirée attachments in the "pieces_jointes" Flysystem storage and
 * keeps their PieceJointe records in sync.
 */
final class PiecesJointes
{
    public const string TAILLE_MAX = '10M';

    public const array TYPES_AUTORISES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        #[Target('pieces_jointes.storage')]
        private readonly FilesystemOperator $storage,
        private readonly Televersement $televersement,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FichierRefuse when the file is too large or of a type not allowed
     */
    public function ajouter(Soiree $soiree, File $fichier, ?User $ajouteePar): PieceJointe
    {
        $stocke = $this->televersement->stocker(
            $this->storage,
            $fichier,
            \sprintf('%d/%d', $soiree->getCollective()->getId(), $soiree->getId()),
            self::TAILLE_MAX,
            self::TYPES_AUTORISES,
        );

        $pieceJointe = new PieceJointe($soiree, $stocke->nomOriginal, $stocke->chemin, $stocke->typeMime, $stocke->taille, $ajouteePar);
        try {
            $this->entityManager->persist($pieceJointe);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->storage->delete($stocke->chemin);

            throw $e;
        }

        return $pieceJointe;
    }

    /**
     * @return resource
     */
    public function lire(PieceJointe $pieceJointe)
    {
        return $this->storage->readStream($pieceJointe->getChemin());
    }

    public function supprimer(PieceJointe $pieceJointe): void
    {
        $pieceJointe->getSoiree()->getPiecesJointes()->removeElement($pieceJointe);
        $this->entityManager->remove($pieceJointe);
        $this->entityManager->flush();

        $this->storage->delete($pieceJointe->getChemin());
    }
}
