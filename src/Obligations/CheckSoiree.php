<?php

namespace App\Obligations;

use App\Entity\Soiree;

/**
 * Something to check on the night itself, e.g. sound level recording above
 * 300 people. Configured with the rules in config/packages/obligations.yaml.
 */
final readonly class CheckSoiree
{
    public function __construct(
        public string $code,
        public string $libelle,
        public Conditions $conditions,
        public string $source,
        public \DateTimeImmutable $verifieeLe,
    ) {
    }

    public function sApplique(Soiree $soiree): bool
    {
        return $this->conditions->tiennent($soiree);
    }
}
