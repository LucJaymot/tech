<?php

namespace App\Controller;

use App\Bilan\BilanAnnuel;
use App\Bilan\BilanSoiree;
use App\Budget\Centimes;
use App\Collective\ActiveCollective;
use App\Entity\Debriefing;
use App\Entity\Soiree;
use App\Entity\User;
use App\EventListener\BilanBloqueListener;
use App\Repository\DebriefingRepository;
use App\Security\Voter\CollectiveVoter;
use App\Security\Voter\SoireeVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Bilans need VIEW_BUDGET on the collective. Records of other collectives
 * are not found (collective filter).
 */
#[IsGranted('ROLE_USER')]
final class BilanController extends AbstractController
{
    public function __construct(
        #[Target('soiree')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    #[Route('/soirees/{id}/bilan', name: 'app_bilan', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, BilanSoiree $bilan, DebriefingRepository $debriefings, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(CollectiveVoter::VIEW_BUDGET, $soiree->getCollective());

        $tous = $debriefings->findPourSoiree($soiree);
        $bloqueurs = $this->workflow->buildTransitionBlockerList($soiree, 'faire_bilan');

        return $this->render('bilan/soiree.html.twig', [
            'soiree' => $soiree,
            'bilan' => $bilan->pour($soiree),
            'debriefings' => $tous,
            'mon_debriefing' => array_values(array_filter($tous, static fn (Debriefing $d): bool => $d->getAuteur() === $user))[0] ?? null,
            'peut_debriefer' => $this->isGranted(SoireeVoter::EDIT, $soiree),
            'peut_faire_bilan' => $bloqueurs->isEmpty(),
            'taches_ouvertes' => array_values(array_filter(iterator_to_array($bloqueurs), static fn (TransitionBlocker $b): bool => BilanBloqueListener::CODE === $b->getCode())),
        ]);
    }

    #[Route('/soirees/{id}/bilan/debriefing', name: 'app_bilan_debriefing', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('debriefing')]
    public function debriefing(Soiree $soiree, Request $request, DebriefingRepository $debriefings, ValidatorInterface $validator, EntityManagerInterface $entityManager, ClockInterface $clock, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        $debriefing = $debriefings->findOneBy(['soiree' => $soiree, 'auteur' => $user]) ?? new Debriefing($soiree, $user);
        $debriefing->setTexte($request->request->getString('texte'), $clock->now());
        $violations = $validator->validate($debriefing);
        if (\count($violations) > 0) {
            $this->addFlash('error', (string) $violations->get(0)->getMessage());
        } else {
            $entityManager->persist($debriefing);
            $entityManager->flush();
            $this->addFlash('success', 'Débriefing enregistré.');
        }

        return $this->redirectToRoute('app_bilan', ['id' => $soiree->getId(), '_fragment' => 'debriefings']);
    }

    #[Route('/soirees/{id}/bilan/faire', name: 'app_bilan_faire', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('faire-bilan')]
    public function faire(Soiree $soiree, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(CollectiveVoter::VIEW_BUDGET, $soiree->getCollective());

        // Roles, status and open tasks are all checked by the workflow guards.
        $bloqueurs = $this->workflow->buildTransitionBlockerList($soiree, 'faire_bilan');
        if (!$bloqueurs->isEmpty()) {
            foreach ($bloqueurs as $bloqueur) {
                if (BilanBloqueListener::CODE === $bloqueur->getCode()) {
                    $this->addFlash('error', $bloqueur->getMessage());
                }
            }
            $this->addFlash('error', 'Le bilan ne peut pas être marqué comme fait.');
        } else {
            $this->workflow->apply($soiree, 'faire_bilan');
            $entityManager->flush();
            $this->addFlash('success', 'Bilan fait.');
        }

        return $this->redirectToRoute('app_bilan', ['id' => $soiree->getId()]);
    }

    #[Route('/bilan/{annee}', name: 'app_bilan_annuel', requirements: ['annee' => '\d{4}'], methods: ['GET'])]
    public function annuel(int $annee, BilanAnnuel $bilan, ActiveCollective $activeCollective): Response
    {
        $collective = $activeCollective->get() ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CollectiveVoter::VIEW_BUDGET, $collective);

        return $this->render('bilan/annuel.html.twig', ['annee' => $annee, 'bilan' => $bilan->pour($collective, $annee)]);
    }

    #[Route('/bilan/{annee}.csv', name: 'app_bilan_annuel_csv', requirements: ['annee' => '\d{4}'], methods: ['GET'])]
    public function annuelCsv(int $annee, BilanAnnuel $bilan, ActiveCollective $activeCollective): Response
    {
        $collective = $activeCollective->get() ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CollectiveVoter::VIEW_BUDGET, $collective);
        $donnees = $bilan->pour($collective, $annee);

        $response = new StreamedResponse(static function () use ($donnees): void {
            $sortie = fopen('php://output', 'w');
            if (false === $sortie) {
                return;
            }
            // Semicolons, decimal commas and a BOM: what a French spreadsheet opens as is.
            fwrite($sortie, "\u{FEFF}");
            $ligne = static fn (array $valeurs) => fputcsv($sortie, $valeurs, ';', '"', '');
            $euros = Centimes::versSaisie(...);
            $ligne(['Soirée', 'Date', 'Dépenses prévues', 'Dépenses réelles', 'Recettes prévues', 'Recettes réelles', 'Solde prévu', 'Solde réel', 'Montants réels manquants']);
            foreach ($donnees['soirees'] as $soiree) {
                $t = $soiree['totaux'];
                $ligne([self::cellule($soiree['nom']), $soiree['date']->format('d/m/Y'), $euros($t->depensesPrevues), $euros($t->depensesReelles), $euros($t->recettesPrevues), $euros($t->recettesReelles), $euros($t->soldePrevu()), $euros($t->soldeReel()), $soiree['sans_reel']]);
            }
            $t = $donnees['total'];
            $ligne(['Total', '', $euros($t['depenses_prevues']), $euros($t['depenses_reelles']), $euros($t['recettes_prevues']), $euros($t['recettes_reelles']), $euros($t['solde_prevu']), $euros($t['solde_reel']), $t['sans_reel']]);
            fclose($sortie);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('bilan-%s-%d.csv', $collective->getSlug(), $annee)));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * A name starting with =, +, - or @ would run as a formula in a spreadsheet.
     */
    private static function cellule(string $texte): string
    {
        return 1 === preg_match('/^[=+\-@\t\r]/', $texte) ? "'".$texte : $texte;
    }
}
