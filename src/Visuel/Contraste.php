<?php

namespace App\Visuel;

/**
 * WCAG 2 contrast between "#rrggbb" colours, so that a collective's accent
 * colour stays readable whatever it picked.
 */
final class Contraste
{
    /** Normal text, WCAG AA. */
    public const float MINIMUM = 4.5;

    public static function ratio(string $a, string $b): float
    {
        [$clair, $sombre] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($clair + 0.05) / ($sombre + 0.05);
    }

    /**
     * Black or white, whichever reads best on $fond.
     */
    public static function texteSur(string $fond): string
    {
        return self::ratio($fond, '#ffffff') >= self::ratio($fond, '#000000') ? '#ffffff' : '#000000';
    }

    /**
     * $couleur, lightened (on a dark $fond) or darkened (on a light one) by
     * steps of 5 % until it reaches the minimum contrast.
     */
    public static function lisibleSur(string $couleur, string $fond): string
    {
        $vers = self::luminance($fond) < 0.5 ? [255, 255, 255] : [0, 0, 0];
        $depart = self::rgb($couleur);
        for ($pas = 0; $pas <= 20; ++$pas) {
            $teinte = array_map(static fn (int $c, int $v): int => (int) round($c + ($v - $c) * $pas / 20), $depart, $vers);
            $hex = \sprintf('#%02x%02x%02x', ...$teinte);
            if (self::ratio($hex, $fond) >= self::MINIMUM) {
                return $hex;
            }
        }

        return \sprintf('#%02x%02x%02x', ...$vers);
    }

    private static function luminance(string $couleur): float
    {
        $lineaire = array_map(static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($couleur));

        return 0.2126 * $lineaire[0] + 0.7152 * $lineaire[1] + 0.0722 * $lineaire[2];
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $couleur): array
    {
        if (1 !== preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $couleur, $p)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a #rrggbb colour.', $couleur));
        }

        return [(int) hexdec($p[1]), (int) hexdec($p[2]), (int) hexdec($p[3])];
    }
}
