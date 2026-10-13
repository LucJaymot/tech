<?php

namespace App\Obligations;

/**
 * A yearly limit on the collective's soirées, e.g. 5 temporary bars a year.
 * Configured in config/packages/obligations.yaml. Indicative only.
 */
final readonly class SeuilAnnuel
{
    /** Criterion → the Soiree boolean property it counts. */
    public const array CRITERES = ['artistes_payes' => 'artistesPayes', 'buvette' => 'buvette', 'soutien' => 'soutien'];

    public function __construct(
        public string $code,
        public string $libelle,
        public string $critere,
        public int $limite,
        public string $source,
        public \DateTimeImmutable $verifieeLe,
    ) {
        if (!isset(self::CRITERES[$critere])) {
            throw new \InvalidArgumentException(\sprintf('Unknown criterion "%s" in the "%s" yearly limit.', $critere, $code));
        }
    }

    public function propriete(): string
    {
        return self::CRITERES[$this->critere];
    }
}
