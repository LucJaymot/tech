<?php

namespace App\Obligations;

use App\Entity\Soiree;

/**
 * When a Regle or a CheckSoiree applies to a soirée: all conditions must hold,
 * an omitted one matches any soirée.
 */
final readonly class Conditions
{
    private const array CONNUES = ['lieu_non_amenage', 'jauge_superieure_a', 'buvette', 'artistes_payes'];

    /**
     * @param array<string, bool|int> $conditions
     */
    public function __construct(
        private array $conditions,
        string $code,
    ) {
        if ([] !== $inconnues = array_diff(array_keys($conditions), self::CONNUES)) {
            throw new \InvalidArgumentException(\sprintf('Unknown condition(s) "%s" in the "%s" rule.', implode('", "', $inconnues), $code));
        }
    }

    public function tiennent(Soiree $soiree): bool
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
}
