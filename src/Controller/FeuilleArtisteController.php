<?php

namespace App\Controller;

use App\Booking\FeuillesArtistes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The artist sheet, opened from a signed link without an account. Read-only.
 */
final class FeuilleArtisteController extends AbstractController
{
    #[Route('/feuille-artiste/{booking}', name: 'app_feuille_artiste', requirements: ['booking' => '\d+'], methods: ['GET'])]
    public function show(int $booking, Request $request, UriSigner $uriSigner, FeuillesArtistes $feuilles): Response
    {
        try {
            $uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            return $this->renderPrivate('feuille_artiste/expiree.html.twig', [], Response::HTTP_GONE);
        } catch (SignedUriException) {
            throw $this->createNotFoundException();
        }

        $feuille = $feuilles->feuille($booking) ?? throw $this->createNotFoundException();

        return $this->renderPrivate('feuille_artiste/show.html.twig', ['feuille' => $feuille]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function renderPrivate(string $view, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($view, $parameters, new Response(status: $status));
        // The link is the only credential: keep it out of caches, search engines and referrers.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
