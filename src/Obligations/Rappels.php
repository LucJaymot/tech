<?php

namespace App\Obligations;

use App\Entity\Collective;
use App\Entity\TacheAdmin;
use App\Enum\MembershipRole;
use App\Obligations\Message\RappelsQuotidiens;
use App\Obligations\Message\RecapitulatifHebdomadaire;
use App\Repository\MembershipRepository;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reminds admins and members of their collective's tasks, through the Notifier.
 */
final class Rappels
{
    /** A task is reminded this many days before its due date, and on the day. */
    private const array JOURS_AVANT = [7, 0];

    /** The weekly summary covers tasks due within this many days, and overdue ones. */
    private const int HORIZON_RECAPITULATIF = 30;

    public function __construct(
        private readonly RetroplanningGenerator $generator,
        private readonly CompteursAnnuels $compteurs,
        private readonly SoireeRepository $soirees,
        private readonly TacheAdminRepository $taches,
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotifierInterface $notifier,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function quotidiens(RappelsQuotidiens $message): void
    {
        $aujourdhui = $this->aujourdhui();

        // Also catches soirées created or changed without SoireeParametresModifies.
        foreach ($this->soirees->findAPartirDu($aujourdhui) as $soiree) {
            $this->generator->generer($soiree);
        }
        $this->entityManager->flush();

        $echeances = array_map(static fn (int $jours): \DateTimeImmutable => $aujourdhui->modify("+$jours days"), self::JOURS_AVANT);
        foreach ($this->taches->findAFaireLe($echeances) as $tache) {
            $this->notifier(new NotificationObligations(
                \sprintf('%s : %s', $tache->getSoiree()->getNom(), $tache->getLibelle()),
                'rappel_obligation',
                ['tache' => $this->tache($tache), 'jours' => (int) $aujourdhui->diff($tache->getEcheance())->days],
            ), $tache->getCollective());
        }
    }

    #[AsMessageHandler]
    public function hebdomadaire(RecapitulatifHebdomadaire $message): void
    {
        $aujourdhui = $this->aujourdhui();

        $parCollectif = [];
        foreach ($this->taches->findAFaireAvant($aujourdhui->modify('+'.self::HORIZON_RECAPITULATIF.' days')) as $tache) {
            $parCollectif[(int) $tache->getCollective()->getId()][] = $tache;
        }

        foreach ($parCollectif as $taches) {
            $collective = $taches[0]->getCollective();
            $alertes = array_values(array_filter($this->compteurs->pour($collective, (int) $aujourdhui->format('Y')), static fn (Compteur $c): bool => $c->enAlerte()));

            $this->notifier(new NotificationObligations(
                \sprintf('%s : démarches de la semaine', $collective->getName()),
                'recapitulatif_obligations',
                [
                    'collective' => $collective->getName(),
                    'taches' => array_map($this->tache(...), $taches),
                    'aujourdhui' => $aujourdhui,
                    'alertes' => array_map(static fn (Compteur $c): array => ['libelle' => $c->seuil->libelle, 'nombre' => $c->nombre, 'limite' => $c->seuil->limite, 'annee' => $c->annee], $alertes),
                ],
            ), $collective);
        }
    }

    private function notifier(NotificationObligations $notification, Collective $collective): void
    {
        foreach ($this->memberships->findBy(['collective' => $collective]) as $membership) {
            if (MembershipRole::Reader !== $membership->getRole()) {
                $this->notifier->send($notification, new Recipient($membership->getUser()->getEmail()));
            }
        }
    }

    /**
     * Plain values: the e-mail context goes through the Messenger queue.
     *
     * @return array{soiree: string, libelle: string, echeance: \DateTimeImmutable, source: string, verifiee_le: \DateTimeImmutable, url: string}
     */
    private function tache(TacheAdmin $tache): array
    {
        return [
            'soiree' => $tache->getSoiree()->getNom(),
            'libelle' => $tache->getLibelle(),
            'echeance' => $tache->getEcheance(),
            'source' => $tache->getSource(),
            'verifiee_le' => $tache->getVerifieeLe(),
            'url' => $this->urlGenerator->generate('app_retroplanning', ['id' => $tache->getSoiree()->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private function aujourdhui(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
