<?php

namespace App\Twig;

use App\Collective\ActiveCollective;
use App\Enum\StyleTitres;
use App\Visuel\Contraste;
use App\Visuel\IdentiteVisuelle;
use Twig\Attribute\AsTwigFunction;

final class AccentExtension
{
    /** Background of the app: noir régie (Tailwind slate-950 in assets/styles/app.css). */
    public const string FOND = '#0E0E0E';

    public function __construct(
        private readonly ActiveCollective $activeCollective,
        private readonly IdentiteVisuelle $identite,
    ) {
    }

    /**
     * CSS variables of a collective's identity, the active one by default:
     * --accent (backgrounds), --accent-texte (text on it), --accent-lisible
     * (accent-coloured text on the app background), contrast checked, and
     * --font-titres (typeface of the titles).
     */
    #[AsTwigFunction('variables_accent')]
    public function variables(?string $accent = null, ?StyleTitres $style = null): string
    {
        $collective = $this->activeCollective->get();
        $accent ??= $collective?->getCouleurAccent() ?? '#6e8bff';
        $style ??= $collective?->getStyleTitres() ?? StyleTitres::Grotesk;

        return \sprintf('--accent: %s; --accent-texte: %s; --accent-lisible: %s; --font-titres: %s;', $accent, Contraste::texteSur($accent), Contraste::lisibleSur($accent, self::FOND), $style->police());
    }

    /** URL of the active collective's logo, null without one. */
    #[AsTwigFunction('logo_collectif')]
    public function logo(): ?string
    {
        $collective = $this->activeCollective->get();

        return null === $collective ? null : $this->identite->urlLogo($collective);
    }
}
