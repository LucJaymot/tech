<?php

namespace App\Controller;

use App\Entity\Affectation;
use App\Entity\Benevole;
use App\Entity\Creneau;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Planning\LiensBenevoles;
use App\Planning\Planning;
use App\Planning\PlanningRefuse;
use App\Repository\BenevoleRepository;
use App\Repository\CreneauRepository;
use App\Repository\DisponibiliteRepository;
use App\Security\Voter\SoireeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Volunteer planning of a soirée. Every member sees it; admins and members
 * change it. Records of other collectives are not found (collective filter).
 */
#[IsGranted('ROLE_USER')]
final class PlanningController extends AbstractController
{
    public function __construct(
        private readonly Planning $planning,
    ) {
    }

    #[Route('/soirees/{id}/planning', name: 'app_planning', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, CreneauRepository $creneaux, BenevoleRepository $benevoles, DisponibiliteRepository $disponibilites, LiensBenevoles $liens): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::VIEW, $soiree);

        $trous = $creneaux->findTrous($soiree);
        $trousParCreneau = [];
        foreach ($trous as $trou) {
            $trousParCreneau[(int) $trou->creneau->getId()] = $trou;
        }
        $peutModifier = $this->isGranted(SoireeVoter::EDIT, $soiree);
        $tousBenevoles = $benevoles->findBy([], ['nom' => 'ASC']);

        return $this->render('planning/show.html.twig', [
            'soiree' => $soiree,
            'trous' => $trous,
            'trous_par_creneau' => $trousParCreneau,
            'manquants' => array_sum(array_map(static fn ($t): int => $t->manquants(), $trous)),
            'benevoles' => $tousBenevoles,
            'disponibles' => $disponibilites->benevolesParCreneau($soiree),
            'peut_modifier' => $peutModifier,
            'liens' => $peutModifier ? array_combine(
                array_map(static fn (Benevole $b): int => (int) $b->getId(), $tousBenevoles),
                array_map(static fn (Benevole $b): string => $liens->lien($b, $soiree), $tousBenevoles),
            ) : [],
        ]);
    }

    #[Route('/soirees/{id}/planning/postes', name: 'app_planning_poste', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planning')]
    public function ajouterPoste(Soiree $soiree, Request $request): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        return $this->essayer($soiree, fn () => $this->planning->ajouterPoste($soiree, $request->request->getString('intitule')), 'Poste ajouté.');
    }

    #[Route('/planning/postes/{id}/creneaux', name: 'app_planning_creneau', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planning')]
    public function ajouterCreneau(Poste $poste, Request $request): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $poste->getSoiree());

        return $this->essayer($poste->getSoiree(), fn () => $this->planning->ajouterCreneau(
            $poste,
            $request->request->getString('debut'),
            $request->request->getString('fin'),
            $request->request->getInt('nombre', 1),
        ), 'Créneau ajouté.', 'poste-'.$poste->getId());
    }

    #[Route('/soirees/{id}/planning/benevoles', name: 'app_planning_benevole', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planning')]
    public function ajouterBenevole(Soiree $soiree, Request $request): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        return $this->essayer($soiree, fn () => $this->planning->ajouterBenevole($soiree, $request->request->getString('nom'), $request->request->getString('email')), 'Bénévole ajouté.', 'benevoles');
    }

    #[Route('/planning/creneaux/{id}/affectations', name: 'app_planning_affecter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planning')]
    public function affecter(Creneau $creneau, Request $request, BenevoleRepository $benevoles): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $creneau->getSoiree());

        // Filtered: volunteers of other collectives are not found.
        $benevole = $benevoles->find($request->request->getInt('benevole')) ?? throw $this->createNotFoundException();

        return $this->essayer($creneau->getSoiree(), fn () => $this->planning->affecter($creneau, $benevole), \sprintf('%s affecté·e.', $benevole->getNom()), 'creneau-'.$creneau->getId());
    }

    #[Route('/planning/affectations/{id}/retirer', name: 'app_planning_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('planning')]
    public function retirer(Affectation $affectation): Response
    {
        $soiree = $affectation->getCreneau()->getSoiree();
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);
        $ancre = 'creneau-'.$affectation->getCreneau()->getId();

        return $this->essayer($soiree, fn () => $this->planning->retirer($affectation), 'Affectation retirée.', $ancre);
    }

    /**
     * @param callable(): mixed $changement
     */
    private function essayer(Soiree $soiree, callable $changement, string $succes, ?string $ancre = null): Response
    {
        try {
            $changement();
            $this->addFlash('success', $succes);
        } catch (PlanningRefuse $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_planning', ['id' => $soiree->getId(), '_fragment' => $ancre]);
    }
}
