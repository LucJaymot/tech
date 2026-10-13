<?php

namespace App\Controller;

use App\Entity\AbonnementPush;
use App\Entity\User;
use App\Notifier\AbonnementPushPayload;
use App\Repository\AbonnementPushRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Push subscriptions of the current user's devices, one per browser. The
 * CSRF token comes in the X-CSRF-Token header: without it, another site
 * could attach its own endpoint to the account and read its notifications.
 */
#[IsGranted('ROLE_USER')]
final class NotificationsPushController extends AbstractController
{
    #[Route('/notifications/abonnement', name: 'app_push_abonner', methods: ['POST'], format: 'json')]
    public function abonner(#[MapRequestPayload] AbonnementPushPayload $payload, Request $request, AbonnementPushRepository $abonnements, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $this->verifierJeton($request);

        // A device belongs to whoever subscribed last on it.
        $existant = $abonnements->findOneBy(['endpoint' => $payload->endpoint]);
        if (null !== $existant) {
            $entityManager->remove($existant);
            $entityManager->flush();
        }
        $entityManager->persist(new AbonnementPush($user, $payload->endpoint, (string) ($payload->keys['p256dh'] ?? ''), (string) ($payload->keys['auth'] ?? ''), $request->headers->get('User-Agent')));
        $entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/notifications/desabonnement', name: 'app_push_desabonner', methods: ['POST'], format: 'json')]
    public function desabonner(Request $request, AbonnementPushRepository $abonnements, EntityManagerInterface $entityManager, #[CurrentUser] User $user): Response
    {
        $this->verifierJeton($request);

        $endpoint = $request->getPayload()->getString('endpoint');
        $abonnement = $abonnements->findOneBy(['endpoint' => $endpoint, 'user' => $user]);
        if (null !== $abonnement) {
            $entityManager->remove($abonnement);
            $entityManager->flush();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('push', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
