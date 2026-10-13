<?php

namespace App\Controller;

use App\Entity\Soiree;
use App\Security\Voter\SoireeVoter;
use App\Visuel\FormatVisuel;
use App\Visuel\GenererVisuels;
use App\Visuel\PagesPubliques;
use App\Visuel\VisuelsLineUp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Line-up visuals and sharing of a soirée, for admins and members.
 */
#[IsGranted('ROLE_USER')]
final class VisuelsController extends AbstractController
{
    #[Route('/soirees/{id}/visuels', name: 'app_visuels', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, VisuelsLineUp $visuels, PagesPubliques $pages): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        $generes = [];
        foreach (FormatVisuel::cases() as $format) {
            $generes[$format->value] = $visuels->genereLe($soiree, $format);
        }

        return $this->render('visuels/show.html.twig', [
            'soiree' => $soiree,
            'formats' => FormatVisuel::cases(),
            'generes' => $generes,
            'vue' => $pages->vue($soiree->getSlug()),
        ]);
    }

    #[Route('/soirees/{id}/visuels', name: 'app_visuels_generer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('visuels')]
    public function generer(Soiree $soiree, MessageBusInterface $bus): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        $bus->dispatch(new GenererVisuels((int) $soiree->getId()));
        $this->addFlash('success', 'Génération lancée : les visuels apparaîtront ici dans un instant.');

        return $this->redirectToRoute('app_visuels', ['id' => $soiree->getId()]);
    }
}
