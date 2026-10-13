<?php

namespace App\Obligations;

/**
 * How many soirées of a calendar year count against a yearly limit.
 */
final readonly class Compteur
{
    public function __construct(
        public SeuilAnnuel $seuil,
        public int $annee,
        public int $nombre,
    ) {
    }

    /**
     * "attention" one soirée before the limit and at it, "bloquant" beyond it.
     */
    public function statut(): string
    {
        return match (true) {
            $this->nombre > $this->seuil->limite => 'bloquant',
            $this->nombre >= $this->seuil->limite - 1 => 'attention',
            default => 'ok',
        };
    }

    public function enAlerte(): bool
    {
        return 'ok' !== $this->statut();
    }
}
