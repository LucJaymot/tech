<?php

namespace App\Twig;

use App\Collective\ActiveCollective;
use App\Visuel\Contraste;
use Twig\Attribute\AsTwigFunction;

final class AccentExtension
{
    /** Background of the app (Tailwind slate-950). */
    public const string FOND = '#020617';

    public function __construct(
        private readonly ActiveCollective $activeCollective,
    ) {
    }

    /**
     * CSS variables of the active collective's accent colour, contrast checked:
     * --accent (backgrounds), --accent-texte (text on it), --accent-lisible
     * (accent-coloured text on the app background).
     */
    #[AsTwigFunction('variables_accent')]
    public function variables(): string
    {
        $accent = $this->activeCollective->get()?->getCouleurAccent() ?? '#6366f1';

        return \sprintf('--accent: %s; --accent-texte: %s; --accent-lisible: %s;', $accent, Contraste::texteSur($accent), Contraste::lisibleSur($accent, self::FOND));
    }
}
