<?php

namespace App\Controller;

use App\Entity\Soiree;
use App\Entity\TacheAdmin;
use App\Obligations\CompteursAnnuels;
use App\Repository\TacheAdminRepository;
use App\Security\Voter\SoireeVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administrative tasks of a soirée. Every member sees them; admins and
 * members tick them. Records of other collectives are not found (collective filter).
 */
#[IsGranted('ROLE_USER')]
final class RetroplanningController extends AbstractController
{
    #[Route('/soirees/{id}/retroplanning', name: 'app_retroplanning', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, TacheAdminRepository $taches, CompteursAnnuels $compteurs, ClockInterface $clock): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::VIEW, $soiree);

        return $this->render('retroplanning/show.html.twig', [
            'soiree' => $soiree,
            'taches' => $taches->findPourSoiree($soiree),
            'compteurs' => $compteurs->pour($soiree->getCollective(), (int) $soiree->getDate()->format('Y')),
            'aujourdhui' => $clock->now()->setTime(0, 0),
            'peut_modifier' => $this->isGranted(SoireeVoter::EDIT, $soiree),
        ]);
    }

    #[Route('/retroplanning/taches/{id}/cocher', name: 'app_retroplanning_cocher', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('retroplanning')]
    public function cocher(TacheAdmin $tache, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $tache->getSoiree());

        $tache->estFaite() ? $tache->decocher() : $tache->cocher($clock->now());
        $entityManager->flush();

        return $this->redirectToRoute('app_retroplanning', ['id' => $tache->getSoiree()->getId(), '_fragment' => 'tache-'.$tache->getId()]);
    }
}
