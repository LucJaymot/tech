<?php

namespace App\Visuel;

use App\Entity\Collective;
use App\Storage\FichierRefuse;
use App\Storage\Televersement;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\File;

/**
 * The collective's logo, stored in the "visuels" storage. PNG or JPEG only:
 * an uploaded SVG could carry scripts into the generated visuals.
 */
final class IdentiteVisuelle
{
    public const string TAILLE_MAX = '1M';

    public const array TYPES_AUTORISES = ['image/png', 'image/jpeg'];

    public function __construct(
        #[Target('visuels.storage')]
        private readonly FilesystemOperator $storage,
        private readonly Televersement $televersement,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Replaces the logo; the caller flushes.
     *
     * @throws FichierRefuse when the file is too large or not a PNG or JPEG
     */
    public function changerLogo(Collective $collective, File $fichier): void
    {
        $stocke = $this->televersement->stocker($this->storage, $fichier, 'logos/'.$collective->getId(), self::TAILLE_MAX, self::TYPES_AUTORISES);

        $ancien = $collective->getLogo();
        $collective->setLogo($stocke->chemin);
        $this->entityManager->flush();
        if (null !== $ancien) {
            $this->storage->delete($ancien);
        }
    }

    /**
     * The logo as a data: URI, to embed in a visual, or null without logo.
     */
    public function logoEnDataUri(Collective $collective): ?string
    {
        $logo = $collective->getLogo();
        if (null === $logo || !$this->storage->fileExists($logo)) {
            return null;
        }

        return \sprintf('data:%s;base64,%s', $this->storage->mimeType($logo), base64_encode($this->storage->read($logo)));
    }
}
