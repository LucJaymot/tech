<?php

namespace App\Controller;

use App\Planning\EspaceBenevole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A volunteer's planning and availabilities for a soirée, opened from their
 * personal signed link, without an account.
 */
final class EspaceBenevoleController extends AbstractController
{
    #[Route('/benevole/{benevole}/soiree/{soiree}', name: 'app_espace_benevole', requirements: ['benevole' => '\d+', 'soiree' => '\d+'], methods: ['GET', 'POST'])]
    public function show(int $benevole, int $soiree, Request $request, UriSigner $uriSigner, EspaceBenevole $espace): Response
    {
        try {
            $uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            return $this->renderPrivate('espace_benevole/expire.html.twig', [], Response::HTTP_GONE);
        } catch (SignedUriException) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            // Visitors have no account: a refusal must not send them to the login page.
            if (!$this->isCsrfTokenValid(\sprintf('disponibilites-%d-%d', $benevole, $soiree), $request->request->getString('_token'))) {
                throw new BadRequestHttpException('Invalid CSRF token.');
            }

            $creneaux = array_values(array_map(intval(...), array_filter($request->request->all('creneaux'), is_numeric(...))));
            if (!$espace->enregistrerDisponibilites($benevole, $soiree, $creneaux)) {
                throw $this->createNotFoundException();
            }
            $this->addFlash('success', 'Disponibilités enregistrées, merci !');

            // Back to the same signed link.
            return $this->redirect($request->getUri());
        }

        $vue = $espace->vue($benevole, $soiree) ?? throw $this->createNotFoundException();

        return $this->renderPrivate('espace_benevole/show.html.twig', [
            'vue' => $vue,
            'token_id' => \sprintf('disponibilites-%d-%d', $benevole, $soiree),
        ]);
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
