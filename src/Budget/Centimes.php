<?php

namespace App\Budget;

/**
 * Conversions between amounts typed in euros ("12,50", "1 200") and cents.
 * Amounts are integers in cents everywhere else: no float ever holds money.
 */
final class Centimes
{
    /**
     * @throws \InvalidArgumentException when the text is not a positive amount with at most two decimals
     */
    public static function depuisSaisie(string $saisie): int
    {
        $normalise = str_replace([' ', "\u{00A0}", "\u{202F}", '€'], '', trim($saisie));
        if (1 !== preg_match('/^(\d{1,9})(?:[.,](\d{1,2}))?$/', $normalise, $parties)) {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est pas un montant valide.', $saisie));
        }

        return (int) $parties[1] * 100 + (int) str_pad($parties[2] ?? '', 2, '0');
    }

    /**
     * For an input field: "1250.5" style, without thousands separator.
     */
    public static function versSaisie(int $centimes): string
    {
        return \sprintf('%s%d,%02d', $centimes < 0 ? '-' : '', intdiv(abs($centimes), 100), abs($centimes) % 100);
    }

    /**
     * For display: "1 250,50 €" with narrow no-break spaces.
     */
    public static function formater(int $centimes): string
    {
        $euros = number_format(intdiv(abs($centimes), 100), 0, ',', "\u{202F}");

        return \sprintf("%s%s,%02d\u{00A0}€", $centimes < 0 ? '−' : '', $euros, abs($centimes) % 100);
    }
}
