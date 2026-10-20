<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use App\Security\Voter\CollectiveVoter;
use App\Soiree\ResumeSoiree;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * A presentation for visitors, the dashboard of the current collective
     * for members, and a word about invitations for accounts without one.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(ActiveCollective $activeCollective, SoireeRepository $soirees, TacheAdminRepository $taches, ClockInterface $clock): Response
    {
        if (null === $this->getUser()) {
            return $this->render('home/presentation.html.twig');
        }

        $collective = $activeCollective->get();
        if (null === $collective) {
            return $this->render('home/sans_collectif.html.twig');
        }

        $maintenant = $clock->now();

        return $this->render('home/index.html.twig', [
            'collective' => $collective,
            'prochaines' => array_map(static fn (Soiree $s): array => [
                'soiree' => $s,
                'compte_a_rebours' => ResumeSoiree::compteAReboursLe($s->getDate(), $maintenant),
            ], $soirees->findProchaines($collective, $maintenant->setTime(0, 0), 3)),
            'taches' => $taches->findOuvertesDuCollectif($collective),
            'aujourdhui' => $maintenant->setTime(0, 0),
            'peut_creer' => $this->isGranted(CollectiveVoter::EDIT, $collective),
        ]);
    }

    #[Route('/a-propos', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }

    #[Route('/confidentialite', name: 'app_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('home/confidentialite.html.twig');
    }

    /** Shown by the service worker when a page cannot be loaded. */
    #[Route('/hors-ligne', name: 'app_hors_ligne', methods: ['GET'])]
    public function horsLigne(): Response
    {
        return $this->render('home/hors_ligne.html.twig');
    }
}
