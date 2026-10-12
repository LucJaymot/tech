<?php

namespace App\Budget;

/**
 * Integer division with explicit rounding, for amounts in cents.
 */
final class Arrondi
{
    /**
     * $dividende / $diviseur rounded half away from zero: 0,5 cent becomes 1 cent.
     */
    public static function diviser(int $dividende, int $diviseur): int
    {
        if ($diviseur <= 0) {
            throw new \InvalidArgumentException('The divisor must be positive.');
        }

        $quotient = intdiv(abs($dividende) * 2 + $diviseur, $diviseur * 2);

        return $dividende < 0 ? -$quotient : $quotient;
    }

    /**
     * $dividende / $diviseur rounded up, for positive amounts.
     */
    public static function diviserParExces(int $dividende, int $diviseur): int
    {
        if ($diviseur <= 0 || $dividende < 0) {
            throw new \InvalidArgumentException('Expected a positive dividend and divisor.');
        }

        return intdiv($dividende + $diviseur - 1, $diviseur);
    }
}
