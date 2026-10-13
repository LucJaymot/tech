<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Entity\Soiree;
use App\Form\SoireeType;
use App\Security\Voter\CollectiveVoter;
use App\Security\Voter\SoireeVoter;
use App\Soiree\ResumeSoiree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Soirées of other collectives are not found (collective filter), hence a
 * 404 that does not reveal they exist.
 */
#[IsGranted('ROLE_USER')]
final class SoireeController extends AbstractController
{
    #[Route('/soirees/{id}', name: 'app_soiree', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, ResumeSoiree $resume): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::VIEW, $soiree);
        $voitBudget = $this->isGranted(CollectiveVoter::VIEW_BUDGET, $soiree->getCollective());

        return $this->render('soiree/show.html.twig', [
            'soiree' => $soiree,
            'compte_a_rebours' => $resume->compteARebours($soiree),
            'horaires' => $resume->horaires($soiree),
            'line_up' => $resume->lineUp($soiree),
            'taches' => $resume->taches($soiree),
            'benevoles' => $resume->benevoles($soiree),
            'guest_lists' => $resume->guestLists($soiree),
            'budget' => $voitBudget ? $resume->budget($soiree) : null,
            'peut_modifier' => $this->isGranted(SoireeVoter::EDIT, $soiree),
        ]);
    }

    /**
     * Saving the soirée publishes SoireeParametresModifies (App\EventListener\SoireeParametresListener).
     */
    #[Route('/soirees/nouvelle', name: 'app_soiree_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, ActiveCollective $activeCollective, EntityManagerInterface $entityManager): Response
    {
        $collective = $activeCollective->get() ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CollectiveVoter::EDIT, $collective);

        // Its status starts at "preparation", the initial place of the "soiree" workflow.
        $form = $this->createForm(SoireeType::class, null, ['collective' => $collective, 'creation' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $soiree = $form->getData();
            \assert($soiree instanceof Soiree);
            $entityManager->persist($soiree);
            $entityManager->flush();

            return $this->enregistree($soiree, 'Soirée créée.');
        }

        return $this->render('soiree/formulaire.html.twig', ['form' => $form, 'soiree' => null]);
    }

    #[Route('/soirees/{id}/modifier', name: 'app_soiree_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Soiree $soiree, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        $form = $this->createForm(SoireeType::class, $soiree, ['collective' => $soiree->getCollective()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->enregistree($soiree, 'Soirée enregistrée.');
        }

        return $this->render('soiree/formulaire.html.twig', ['form' => $form, 'soiree' => $soiree]);
    }

    private function enregistree(Soiree $soiree, string $message): Response
    {
        $this->addFlash('success', $message);
        if ($soiree->depasseCapacite()) {
            $this->addFlash('warning', \sprintf('La jauge (%d) dépasse la capacité du lieu (%d).', $soiree->getJauge(), $soiree->getLieu()?->getCapacite()));
        }

        return $this->redirectToRoute('app_soiree', ['id' => $soiree->getId()]);
    }
}
