<?php

namespace App\Controller;

use App\Booking\FeuillesArtistes;
use App\Booking\Programmation;
use App\Entity\Booking;
use App\Entity\Soiree;
use App\Enum\BookingStatut;
use App\Form\ProgrammationType;
use App\Repository\PassageRepository;
use App\Security\Voter\BookingVoter;
use App\Security\Voter\SoireeVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The artists of a soirée. Every member sees the line-up; admins and members
 * book artists and move their status on; contacts and the artist sheet link
 * are for them only. Records of other collectives are not found (collective filter).
 */
#[IsGranted('ROLE_USER')]
final class LineUpController extends AbstractController
{
    /** Labels of the "booking" workflow transitions, as buttons. */
    public const array TRANSITIONS = [
        'negocier' => 'Passer en négociation',
        'confirmer' => 'Confirmer',
        'signer_contrat' => 'Contrat signé',
        'declarer_guso' => 'Déclaré au Guso',
        'payer' => 'Marquer payé',
    ];

    public function __construct(
        #[Target('booking')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    #[Route('/soirees/{id}/line-up', name: 'app_line_up', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, PassageRepository $passages, FeuillesArtistes $feuilles): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::VIEW, $soiree);
        $peutModifier = $this->isGranted(SoireeVoter::EDIT, $soiree);

        $programmes = [];
        foreach ($soiree->getBookings() as $booking) {
            $programmes[] = [
                'booking' => $booking,
                'transitions' => $peutModifier ? array_values(array_unique(array_map(static fn ($t): string => $t->getName(), $this->workflow->getEnabledTransitions($booking)))) : [],
                'lien_feuille' => $peutModifier ? $feuilles->lien($booking) : null,
                'retirable' => $peutModifier && \in_array($booking->getStatutEnum(), [BookingStatut::Contacte, BookingStatut::Negociation], true),
            ];
        }
        // In timetable order, artists still without a time last.
        usort($programmes, static fn (array $a, array $b): int => [null === $a['booking']->getPassage(), $a['booking']->getPassage()?->getDebut(), $a['booking']->getArtiste()->getNom()]
            <=> [null === $b['booking']->getPassage(), $b['booking']->getPassage()?->getDebut(), $b['booking']->getArtiste()->getNom()]);

        return $this->render('line_up/show.html.twig', [
            'soiree' => $soiree,
            'programmes' => $programmes,
            'timetable' => $passages->findTimetable($soiree),
            'peut_modifier' => $peutModifier,
            'transitions' => self::TRANSITIONS,
        ]);
    }

    #[Route('/soirees/{id}/line-up/ajouter', name: 'app_line_up_ajouter', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function ajouter(Soiree $soiree, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        $form = $this->createForm(ProgrammationType::class, null, ['soiree' => $soiree]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $booking = $form->getData();
            \assert($booking instanceof Booking);
            $this->enregistrer($booking, $entityManager);
            $this->addFlash('success', \sprintf('%s ajouté au line-up.', $booking->getArtiste()->getNom()));

            return $this->redirectToRoute('app_line_up', ['id' => $soiree->getId(), '_fragment' => 'booking-'.$booking->getId()]);
        }

        return $this->render('line_up/formulaire.html.twig', ['form' => $form, 'soiree' => $soiree, 'booking' => null]);
    }

    #[Route('/line-up/{id}/modifier', name: 'app_line_up_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Booking $booking, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(BookingVoter::EDIT, $booking);

        $form = $this->createForm(ProgrammationType::class, $booking, ['soiree' => $booking->getSoiree(), 'edition' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->enregistrer($booking, $entityManager);
            $this->addFlash('success', \sprintf('%s enregistré.', $booking->getArtiste()->getNom()));

            return $this->redirectToRoute('app_line_up', ['id' => $booking->getSoiree()->getId(), '_fragment' => 'booking-'.$booking->getId()]);
        }

        return $this->render('line_up/formulaire.html.twig', ['form' => $form, 'soiree' => $booking->getSoiree(), 'booking' => $booking]);
    }

    /**
     * Roles and order are checked by the workflow guards (App\Security\Voter\BookingVoter).
     */
    #[Route('/line-up/{id}/statut/{transition}', name: 'app_line_up_statut', requirements: ['id' => '\d+', 'transition' => '[a-z_]+'], methods: ['POST'])]
    #[IsCsrfTokenValid('line-up')]
    public function statut(Booking $booking, string $transition, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(BookingVoter::EDIT, $booking);

        if (!isset(self::TRANSITIONS[$transition]) || !$this->workflow->can($booking, $transition)) {
            $this->addFlash('error', 'Ce changement de statut n\'est pas possible.');
        } else {
            $this->workflow->apply($booking, $transition);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('%s : %s.', $booking->getArtiste()->getNom(), mb_strtolower($booking->getStatutEnum()->label())));
        }

        return $this->redirectToRoute('app_line_up', ['id' => $booking->getSoiree()->getId(), '_fragment' => 'booking-'.$booking->getId()]);
    }

    /**
     * Only before confirmation: a confirmed artist has a fee line and a contract.
     */
    #[Route('/line-up/{id}/retirer', name: 'app_line_up_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('line-up')]
    public function retirer(Booking $booking, Programmation $programmation, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(BookingVoter::EDIT, $booking);
        $soiree = $booking->getSoiree();

        if (!\in_array($booking->getStatutEnum(), [BookingStatut::Contacte, BookingStatut::Negociation], true)) {
            $this->addFlash('error', 'Un artiste confirmé ne se retire pas du line-up.');
        } else {
            $programmation->retirer($booking);
            $entityManager->remove($booking);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('%s retiré du line-up.', $booking->getArtiste()->getNom()));
        }

        return $this->redirectToRoute('app_line_up', ['id' => $soiree->getId()]);
    }

    /**
     * A paid artist makes the soirée one with paid artists, for its obligations.
     */
    private function enregistrer(Booking $booking, EntityManagerInterface $entityManager): void
    {
        if ($booking->getCachet() > 0) {
            $booking->getSoiree()->setArtistesPayes(true);
        }
        $entityManager->persist($booking);
        $entityManager->flush();
    }
}
