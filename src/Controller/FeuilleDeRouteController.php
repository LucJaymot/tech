<?php

namespace App\Controller;

use App\Entity\Soiree;
use App\Security\Voter\SoireeVoter;
use App\Soiree\FeuilleDeRoute;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only sheet for the night, kept offline by public/sw.js. Contact
 * details are for admins and members only.
 */
#[IsGranted('ROLE_USER')]
final class FeuilleDeRouteController extends AbstractController
{
    #[Route('/soirees/{id}/feuille-de-route', name: 'app_feuille_de_route', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, FeuilleDeRoute $feuilleDeRoute, ClockInterface $clock): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::VIEW, $soiree);

        return $this->render('feuille_de_route/show.html.twig', [
            'vue' => $feuilleDeRoute->pour($soiree),
            'maintenant' => $clock->now(),
            'contacts' => $this->isGranted(SoireeVoter::EDIT, $soiree),
        ]);
    }
}
