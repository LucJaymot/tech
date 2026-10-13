<?php

namespace App\Visuel;

/**
 * Sizes of the line-up visuals, in pixels.
 */
enum FormatVisuel: string
{
    case Carre = 'carre';
    case Story = 'story';

    public function largeur(): int
    {
        return 1080;
    }

    public function hauteur(): int
    {
        return match ($this) {
            self::Carre => 1080,
            self::Story => 1920,
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Carre => 'Carré 1080 × 1080',
            self::Story => 'Story 1080 × 1920',
        };
    }
}
