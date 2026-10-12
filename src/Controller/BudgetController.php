<?php

namespace App\Controller;

use App\Budget\Justificatifs;
use App\Entity\Justificatif;
use App\Entity\LigneBudget;
use App\Entity\Soiree;
use App\Entity\User;
use App\Security\Voter\CollectiveVoter;
use App\Storage\FichierRefuse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Soirées of other collectives are not found (collective filter), hence 404.
 */
#[IsGranted('ROLE_USER')]
final class BudgetController extends AbstractController
{
    #[Route('/soirees/{id}/budget', name: 'app_budget', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree): Response
    {
        $this->denyAccessUnlessGranted(CollectiveVoter::VIEW_BUDGET, $soiree->getCollective());

        return $this->render('budget/show.html.twig', ['soiree' => $soiree]);
    }

    #[Route('/budget/lignes/{id}/justificatifs', name: 'app_budget_justificatif_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('justificatif')]
    public function ajouterJustificatif(LigneBudget $ligne, Request $request, Justificatifs $justificatifs, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(CollectiveVoter::EDIT, $ligne->getCollective());

        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile) {
            $this->addFlash('error', 'Choisissez un fichier.');
        } else {
            try {
                $justificatifs->ajouter($ligne, $fichier, $user);
                $this->addFlash('success', 'Justificatif ajouté.');
            } catch (FichierRefuse $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_budget', ['id' => $ligne->getSoiree()->getId(), '_fragment' => 'ligne-'.$ligne->getId()]);
    }

    #[Route('/budget/justificatifs/{id}', name: 'app_budget_justificatif', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function telechargerJustificatif(Justificatif $justificatif, Justificatifs $justificatifs): Response
    {
        $this->denyAccessUnlessGranted(CollectiveVoter::VIEW_BUDGET, $justificatif->getCollective());

        $flux = $justificatifs->lire($justificatif);
        $response = new StreamedResponse(static function () use ($flux): void {
            fpassthru($flux);
            fclose($flux);
        });
        $nomAscii = preg_replace('/[^\x20-\x7E]|["\\\\\/%]/', '_', $justificatif->getNomOriginal()) ?: 'justificatif';
        $response->headers->set('Content-Type', $justificatif->getTypeMime());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, str_replace(['/', '\\'], '_', $justificatif->getNomOriginal()), $nomAscii));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
