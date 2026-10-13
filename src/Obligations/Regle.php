<?php

namespace App\Obligations;

use App\Entity\Soiree;

/**
 * An administrative obligation of a soirée, configured in
 * config/packages/obligations.yaml. Indicative only.
 */
final readonly class Regle
{
    private const array CONDITIONS = ['lieu_non_amenage', 'jauge_superieure_a', 'buvette', 'artistes_payes'];

    /**
     * @param array<string, bool|int> $conditions    all must hold
     * @param int                     $decalageJours due date relative to the soirée, negative = before
     */
    public function __construct(
        public string $code,
        public string $libelle,
        public array $conditions,
        public int $decalageJours,
        public string $source,
        public \DateTimeImmutable $verifieeLe,
    ) {
        if ([] !== $inconnues = array_diff(array_keys($conditions), self::CONDITIONS)) {
            throw new \InvalidArgumentException(\sprintf('Unknown condition(s) "%s" in the "%s" rule.', implode('", "', $inconnues), $code));
        }
    }

    public function sApplique(Soiree $soiree): bool
    {
        foreach ($this->conditions as $condition => $attendu) {
            $tient = match ($condition) {
                // An unknown lieu or jauge never matches.
                'lieu_non_amenage' => $attendu === (null !== $soiree->getLieu() && !$soiree->getLieu()->isAmenagePourPublic()),
                'jauge_superieure_a' => null !== $soiree->getJauge() && $soiree->getJauge() > $attendu,
                'buvette' => $attendu === $soiree->hasBuvette(),
                'artistes_payes' => $attendu === $soiree->hasArtistesPayes(),
                default => throw new \LogicException('Checked by the constructor.'),
            };
            if (!$tient) {
                return false;
            }
        }

        return true;
    }

    public function echeance(Soiree $soiree): \DateTimeImmutable
    {
        return $soiree->getDate()->modify(\sprintf('%+d days', $this->decalageJours));
    }
}
