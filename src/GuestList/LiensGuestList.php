<?php

namespace App\GuestList;

use App\Entity\Booking;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signed links for artists to fill their guest list without an account.
 */
final class LiensGuestList
{
    public const string DUREE_PAR_DEFAUT = '+30 days';

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function lien(Booking|int $booking, ?\DateTimeImmutable $expireLe = null): string
    {
        $url = $this->urlGenerator->generate('app_guest_list_artiste', ['booking' => $booking instanceof Booking ? $booking->getId() : $booking], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, $expireLe ?? new \DateTimeImmutable(self::DUREE_PAR_DEFAUT));
    }
}
