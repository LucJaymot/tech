<?php

namespace App\Storage;

final readonly class FichierStocke
{
    public function __construct(
        /** Key in the storage. */
        public string $chemin,
        /** Name sent by the browser, for display only. */
        public string $nomOriginal,
        public string $typeMime,
        /** In bytes. */
        public int $taille,
    ) {
    }
}
