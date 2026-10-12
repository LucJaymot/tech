<?php

namespace App\Planning;

use App\Entity\Soiree;

/**
 * Turns hours typed for a soirée ("22:00", "00:30") into date-times.
 */
final class Horaires
{
    /**
     * A morning hour on an evening soirée belongs to the night after its date.
     *
     * @throws \InvalidArgumentException when $heure is not "HH:MM"
     */
    public static function surLaSoiree(Soiree $soiree, string $heure): \DateTimeImmutable
    {
        if (1 !== preg_match('/^([01]?\d|2[0-3])[:hH]([0-5]\d)$/', trim($heure), $parties)) {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est pas une heure valide (HH:MM).', $heure));
        }

        $moment = $soiree->getDate()->setTime((int) $parties[1], (int) $parties[2]);
        $soireeDuSoir = (int) $soiree->getOuverture()->format('H') >= 12;

        return $soireeDuSoir && (int) $parties[1] < 12 ? $moment->modify('+1 day') : $moment;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} the end moves to the next day when it is not after the start
     */
    public static function plage(Soiree $soiree, string $debut, string $fin): array
    {
        $debutAt = self::surLaSoiree($soiree, $debut);
        $finAt = self::surLaSoiree($soiree, $fin);
        while ($finAt <= $debutAt) {
            $finAt = $finAt->modify('+1 day');
        }

        return [$debutAt, $finAt];
    }
}
