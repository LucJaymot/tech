<?php

namespace App\Planning;

use App\Entity\Creneau;

/**
 * A créneau with fewer volunteers assigned than required.
 */
final readonly class Trou
{
    public function __construct(
        public Creneau $creneau,
        public int $requis,
        public int $affectes,
    ) {
    }

    public function manquants(): int
    {
        return $this->requis - $this->affectes;
    }
}
