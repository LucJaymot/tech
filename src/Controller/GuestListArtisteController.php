<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Entity\Booking;
use App\Enum\SoireeStatut;
use App\GuestList\GuestListRefusee;
use App\GuestList\GuestLists;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An artist's guest list, filled from a signed link without an account.
 * The quota is checked by the server, whatever the browser sends.
 */
final class GuestListArtisteController extends AbstractController
{
    #[Route('/guest-list/{booking}', name: 'app_guest_list_artiste', requirements: ['booking' => '\d+'], methods: ['GET', 'POST'])]
    public function show(int $booking, Request $request, UriSigner $uriSigner, ActiveCollective $activeCollective, EntityManagerInterface $entityManager, GuestLists $guestLists): Response
    {
        try {
            $uriSigner->verify($request);
        } catch (ExpiredSignedUriException) {
            return $this->prive($this->render('feuille_artiste/expiree.html.twig', [], new Response(status: Response::HTTP_GONE)));
        } catch (SignedUriException) {
            throw $this->createNotFoundException();
        }

        $tokenId = 'guest-list-artiste-'.$booking;
        if ($request->isMethod('POST') && !$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            // Visitors have no account: a refusal must not send them to the login page.
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        // No account, hence no active collective: work outside the collective filter.
        $vue = $activeCollective->withoutFilter(function () use ($booking, $request, $entityManager, $guestLists): ?array {
            $entite = $entityManager->find(Booking::class, $booking);
            if (null === $entite) {
                return null;
            }

            $liste = $guestLists->pourBooking($entite);
            $modifiable = \in_array($entite->getSoiree()->getStatutEnum(), [SoireeStatut::Preparation, SoireeStatut::Confirmee], true);

            if ($request->isMethod('POST')) {
                if (!$modifiable) {
                    return null;
                }
                try {
                    if ($request->request->has('retirer')) {
                        $invite = $liste->getInvites()->findFirst(static fn ($k, $i): bool => $i->getId() === $request->request->getInt('retirer'));
                        if (null !== $invite) {
                            $guestLists->retirer($invite);
                            $this->addFlash('success', \sprintf('%s retiré·e.', $invite->getNom()));
                        }
                    } else {
                        $invite = $guestLists->ajouter($liste, $request->request->getString('nom'), $request->request->getInt('accompagnants'));
                        $this->addFlash('success', \sprintf('%s ajouté·e.', $invite->getNom()));
                    }
                } catch (GuestListRefusee $e) {
                    $this->addFlash('error', $e->getMessage());
                }

                return ['redirect' => true];
            }

            return [
                'artiste' => $entite->getArtiste()->getNom(),
                'soiree' => $entite->getSoiree()->getNom(),
                'date' => $entite->getSoiree()->getDate(),
                'quota' => $liste->getQuota(),
                'restantes' => $liste->placesRestantes(),
                'modifiable' => $modifiable,
                'invites' => $liste->getInvites()->map(static fn ($i): array => [
                    'id' => $i->getId(),
                    'nom' => $i->getNom(),
                    'accompagnants' => $i->getAccompagnants(),
                    'arrive' => $i->estPointe(),
                ])->getValues(),
            ];
        });

        if (null === $vue) {
            throw $this->createNotFoundException();
        }
        if (isset($vue['redirect'])) {
            return $this->redirect($request->getUri());
        }

        return $this->prive($this->render('guest_list/artiste.html.twig', ['vue' => $vue, 'token_id' => $tokenId]));
    }

    private function prive(Response $response): Response
    {
        // The link is the only credential: keep it out of caches, search engines and referrers.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
