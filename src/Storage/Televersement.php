<?php

namespace App\Storage;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates an incoming file and writes it to a Flysystem storage under a
 * generated key. The type and extension come from the content, never from
 * the name sent by the browser.
 */
final class Televersement
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param list<string> $typesAutorises MIME types
     *
     * @throws FichierRefuse when the file is too large, of a type not allowed or unreadable
     */
    public function stocker(FilesystemOperator $storage, File $fichier, string $repertoire, string $tailleMax, array $typesAutorises): FichierStocke
    {
        $violations = $this->validator->validate($fichier, new Assert\File(maxSize: $tailleMax, mimeTypes: $typesAutorises));
        if (\count($violations) > 0) {
            throw new FichierRefuse((string) $violations->get(0)->getMessage());
        }

        $taille = $fichier->getSize();
        if (false === $taille) {
            throw new FichierRefuse('Le fichier n\'a pas pu être lu.');
        }

        $typeMime = $fichier->getMimeType() ?? 'application/octet-stream';
        $chemin = \sprintf('%s/%s.%s', trim($repertoire, '/'), bin2hex(random_bytes(16)), $fichier->guessExtension() ?? 'bin');
        $nomOriginal = $fichier instanceof UploadedFile ? $fichier->getClientOriginalName() : $fichier->getFilename();

        $flux = fopen($fichier->getPathname(), 'r');
        if (false === $flux) {
            throw new FichierRefuse('Le fichier n\'a pas pu être lu.');
        }
        try {
            $storage->writeStream($chemin, $flux, ['mimetype' => $typeMime]);
        } finally {
            fclose($flux);
        }

        return new FichierStocke($chemin, mb_substr($nomOriginal, 0, 255), $typeMime, $taille);
    }
}
