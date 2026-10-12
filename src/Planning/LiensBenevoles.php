<?php

namespace App\Planning;

use App\Entity\Benevole;
use App\Entity\Soiree;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Personal links of volunteers: one per volunteer and soirée, signed, valid
 * until two days after the soirée, usable without an account.
 */
final class LiensBenevoles
{
    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function lien(Benevole $benevole, Soiree $soiree): string
    {
        $url = $this->urlGenerator->generate('app_espace_benevole', ['benevole' => $benevole->getId(), 'soiree' => $soiree->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, self::expiration($soiree));
    }

    /**
     * End of the second day after the soirée.
     */
    public static function expiration(Soiree $soiree): \DateTimeImmutable
    {
        return $soiree->getDate()->setTime(0, 0)->modify('+3 days');
    }
}
