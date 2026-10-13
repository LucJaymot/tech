<?php

namespace App\Controller;

use App\Entity\Soiree;
use App\Entity\User;
use App\GuestList\Pointage;
use App\Security\Voter\SoireeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Door check-in for admins and members. The page works offline; it talks to
 * the server through the two JSON endpoints below.
 */
#[IsGranted('ROLE_USER')]
final class PointageController extends AbstractController
{
    public const string CSRF_ID = 'pointage';

    #[Route('/soirees/{id}/pointage', name: 'app_pointage', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        return $this->render('pointage/show.html.twig', ['soiree' => $soiree, 'csrf_id' => self::CSRF_ID]);
    }

    #[Route('/soirees/{id}/pointage/invites', name: 'app_pointage_invites', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function invites(Soiree $soiree, Pointage $pointage): JsonResponse
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        return $this->prive(new JsonResponse(['invites' => $pointage->liste($soiree)]));
    }

    /**
     * Idempotent: a device may send the same check-ins again after a lost answer.
     */
    #[Route('/soirees/{id}/pointage/synchronisation', name: 'app_pointage_synchronisation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function synchroniser(Soiree $soiree, Request $request, Pointage $pointage, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        try {
            $corps = $request->toArray();
        } catch (\Throwable $e) {
            throw new BadRequestHttpException('Expected a JSON object.', $e);
        }
        $pointages = $corps['pointages'] ?? null;
        if (!\is_array($pointages) || !array_is_list($pointages)) {
            throw new BadRequestHttpException('Expected a "pointages" list.');
        }

        return $this->prive(new JsonResponse(['resultats' => $pointage->synchroniser($soiree, $pointages, $user)]));
    }

    private function prive(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
