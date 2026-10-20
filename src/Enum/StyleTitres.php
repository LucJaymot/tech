<?php

namespace App\Enum;

/**
 * Typeface of a collective's page titles, among the fonts the app ships with
 * (assets/styles/app.css): no font is downloaded from elsewhere.
 */
enum StyleTitres: string
{
    case Grotesk = 'grotesk';
    case Mono = 'mono';
    case Matrice = 'matrice';

    public function label(): string
    {
        return match ($this) {
            self::Grotesk => 'Grotesk (Space Grotesk)',
            self::Mono => 'Mono (IBM Plex Mono)',
            self::Matrice => 'Matrice (Doto, à points)',
        };
    }

    /** The CSS font variable of the Tailwind theme. */
    public function police(): string
    {
        return match ($this) {
            self::Grotesk => 'var(--font-sans)',
            self::Mono => 'var(--font-mono)',
            self::Matrice => 'var(--font-doto)',
        };
    }
}
