<?php

namespace App\Soiree;

use App\Entity\PieceJointe;
use App\Entity\Soiree;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws PieceJointeRefusee when the file is too large or of a type not allowed
     */
    public function ajouter(Soiree $soiree, File $fichier, ?User $ajouteePar): PieceJointe
    {
        $violations = $this->validator->validate($fichier, new Assert\File(maxSize: self::TAILLE_MAX, mimeTypes: self::TYPES_AUTORISES));
        if (\count($violations) > 0) {
            throw new PieceJointeRefusee((string) $violations->get(0)->getMessage());
        }

        $taille = $fichier->getSize();
        if (false === $taille) {
            throw new PieceJointeRefusee('Le fichier n\'a pas pu être lu.');
        }

        // Type and extension come from the content, the stored name never from the user.
        $typeMime = $fichier->getMimeType() ?? 'application/octet-stream';
        $chemin = \sprintf('%d/%d/%s.%s', $soiree->getCollective()->getId(), $soiree->getId(), bin2hex(random_bytes(16)), $fichier->guessExtension() ?? 'bin');
        $nomOriginal = $fichier instanceof UploadedFile ? $fichier->getClientOriginalName() : $fichier->getFilename();

        $flux = fopen($fichier->getPathname(), 'r');
        if (false === $flux) {
            throw new PieceJointeRefusee('Le fichier n\'a pas pu être lu.');
        }
        try {
            $this->storage->writeStream($chemin, $flux, ['mimetype' => $typeMime]);
        } finally {
            fclose($flux);
        }

        $pieceJointe = new PieceJointe($soiree, mb_substr($nomOriginal, 0, 255), $chemin, $typeMime, $taille, $ajouteePar);
        try {
            $this->entityManager->persist($pieceJointe);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->storage->delete($chemin);

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
