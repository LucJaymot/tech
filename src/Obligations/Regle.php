<?php

namespace App\Obligations;

use App\Entity\Soiree;

/**
 * An administrative obligation of a soirée, configured in
 * config/packages/obligations.yaml. Indicative only.
 */
final readonly class Regle
{
    /**
     * @param int $decalageJours due date relative to the soirée, negative = before
     */
    public function __construct(
        public string $code,
        public string $libelle,
        public Conditions $conditions,
        public int $decalageJours,
        public string $source,
        public \DateTimeImmutable $verifieeLe,
    ) {
    }

    public function sApplique(Soiree $soiree): bool
    {
        return $this->conditions->tiennent($soiree);
    }

    public function echeance(Soiree $soiree): \DateTimeImmutable
    {
        return $soiree->getDate()->modify(\sprintf('%+d days', $this->decalageJours));
    }
}
