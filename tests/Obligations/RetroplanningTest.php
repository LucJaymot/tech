<?php

namespace App\Tests\Obligations;

use App\Entity\Lieu;
use App\Entity\Soiree;
use App\Entity\TacheAdmin;
use App\Event\SoireeParametresModifies;
use App\Obligations\Compteur;
use App\Obligations\CompteursAnnuels;
use App\Obligations\Message\RappelsQuotidiens;
use App\Obligations\Message\RecapitulatifHebdomadaire;
use App\Obligations\Rappels;
use App\Obligations\RetroplanningGenerator;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use App\Soiree\SoireeDuplicator;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;

/**
 * Relies on AppFixtures: "Concert de printemps" of the Jardin (fitted-out
 * venue, jauge 100, bar, paid artists). Camille, Nora and Théo are admin or
 * member there, Inès reader.
 */
final class RetroplanningTest extends KernelTestCase
{
    use ActsAsMember;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    public function testFixtureSoireeHasTheTasksOfItsRules(): void
    {
        $soiree = $this->soiree();

        self::assertSame(
            ['spectacle_occasionnel' => -30, 'sacem' => -15, 'buvette_temporaire' => -15, 'guso_dus' => 15],
            $this->echeances($soiree),
        );
    }

    public function testGeneratingAgainChangesNothing(): void
    {
        $soiree = $this->soiree();
        $avant = array_map(static fn (TacheAdmin $t): ?int => $t->getId(), $this->taches($soiree));

        $this->generator()->generer($soiree);
        $this->entityManager()->flush();

        self::assertSame($avant, array_map(static fn (TacheAdmin $t): ?int => $t->getId(), $this->taches($soiree)));
    }

    public function testChangedParametersRescheduleThroughMessenger(): void
    {
        $soiree = $this->soiree();
        $lieu = (new Lieu($soiree->getCollective(), 'Hangar', 'Quai 3', 2000))->setAmenagePourPublic(false);
        $this->entityManager()->persist($lieu);
        $soiree->setDate($soiree->getDate()->modify('+7 days'))->setLieu($lieu)->setJauge(600)->setBuvette(false);
        $this->entityManager()->flush();

        $this->traiterLesMessages();

        self::assertSame(
            ['spectacle_occasionnel' => -30, 'rassemblement_festif' => -30, 'sacem' => -15, 'guso_dus' => 15],
            $this->echeances($soiree),
        );
    }

    public function testDoneTasksAreNeverTouched(): void
    {
        $soiree = $this->soiree();
        $sacem = $this->tache($soiree, 'sacem');
        $sacem->cocher(new \DateTimeImmutable());
        $this->tache($soiree, 'guso_dus')->cocher(new \DateTimeImmutable());
        $this->entityManager()->flush();
        $echeanceSacem = $sacem->getEcheance()->format('Y-m-d');

        $soiree->setDate($soiree->getDate()->modify('+7 days'))->setArtistesPayes(false);
        $this->entityManager()->flush();
        $this->traiterLesMessages();

        self::assertSame($echeanceSacem, $this->tache($soiree, 'sacem')->getEcheance()->format('Y-m-d'));
        self::assertTrue($this->tache($soiree, 'guso_dus')->estFaite(), 'kept although its rule no longer applies');
        self::assertArrayNotHasKey('spectacle_occasionnel', $this->echeances($soiree));
    }

    public function testDailyRemindersGoToAdminsAndMembersOnTheDayAndAWeekBefore(): void
    {
        $soiree = $this->soiree();
        // Sacem and bar are due J-15: a week from J-22.
        static::mockTime($soiree->getDate()->modify('-22 days')->format('Y-m-d 07:00'));

        static::getContainer()->get(Rappels::class)->quotidiens(new RappelsQuotidiens());

        $sujets = $this->sujetsParDestinataire();
        self::assertSame(['camille@example.com', 'nora@example.com', 'theo@example.com'], array_keys($sujets));
        self::assertSame(['Concert de printemps : Déclarer la soirée à la Sacem', 'Concert de printemps : Demander l\'autorisation de buvette temporaire (groupes 1 et 3) en mairie'], $sujets['theo@example.com']);
        self::assertStringContainsString('à faire d\'ici le '.$soiree->getDate()->modify('-15 days')->format('d/m/Y'), $this->texte(0));
        self::assertStringContainsString('Règle indicative, source vérifiée le 13/10/2026', $this->texte(0));
    }

    public function testDailyRemindersSkipDoneTasks(): void
    {
        $soiree = $this->soiree();
        $this->tache($soiree, 'sacem')->cocher(new \DateTimeImmutable());
        $this->tache($soiree, 'buvette_temporaire')->cocher(new \DateTimeImmutable());
        $this->entityManager()->flush();
        static::mockTime($soiree->getDate()->modify('-15 days')->format('Y-m-d 07:00'));

        static::getContainer()->get(Rappels::class)->quotidiens(new RappelsQuotidiens());

        self::assertSame([], $this->emails());
    }

    public function testCancelledSoireesAreNeitherRemindedNorCounted(): void
    {
        $soiree = $this->soiree();
        $soiree->setStatut('annulee');
        $this->entityManager()->flush();
        $annee = (int) $soiree->getDate()->format('Y');

        static::mockTime($soiree->getDate()->modify('-22 days')->format('Y-m-d 07:00'));
        static::getContainer()->get(Rappels::class)->quotidiens(new RappelsQuotidiens());
        static::getContainer()->get(Rappels::class)->hebdomadaire(new RecapitulatifHebdomadaire());

        self::assertSame([], $this->emails());
        self::assertSame(0, static::getContainer()->get(CompteursAnnuels::class)->pour($soiree->getCollective(), $annee)[1]->nombre);
        self::assertSame([], static::getContainer()->get(TacheAdminRepository::class)->findOuvertesDuCollectif($soiree->getCollective()), 'not on the home page');
    }

    public function testWeeklySummaryListsTasksDueWithin30DaysAndOverdueOnes(): void
    {
        $soiree = $this->soiree();
        static::mockTime($soiree->getDate()->modify('-20 days')->format('Y-m-d 08:00'));

        static::getContainer()->get(Rappels::class)->hebdomadaire(new RecapitulatifHebdomadaire());

        self::assertSame(['camille@example.com', 'nora@example.com', 'theo@example.com'], array_keys($this->sujetsParDestinataire()));
        $texte = $this->texte(0);
        self::assertStringContainsString('EN RETARD · '.$soiree->getDate()->modify('-30 days')->format('d/m/Y').' · Concert de printemps : Déclarer la représentation', $texte);
        self::assertStringContainsString('Déclarer la soirée à la Sacem', $texte);
        self::assertStringNotContainsString('Guso', $texte, 'due in 35 days');
    }

    public function testYearlyCountersAlertOneSoireeBeforeTheLimit(): void
    {
        $soiree = $this->soiree();
        $duplicator = static::getContainer()->get(SoireeDuplicator::class);
        for ($i = 0; $i < 3; ++$i) {
            $this->entityManager()->persist($duplicator->duplicate($soiree, $soiree->getDate()));
        }
        $this->entityManager()->flush();

        $compteurs = static::getContainer()->get(CompteursAnnuels::class)->pour($soiree->getCollective(), (int) $soiree->getDate()->format('Y'));

        self::assertSame(
            ['representations' => [4, 'ok'], 'buvettes' => [4, 'attention'], 'manifestations_soutien' => [0, 'ok']],
            array_combine(
                array_map(static fn (Compteur $c): string => $c->seuil->code, $compteurs),
                array_map(static fn (Compteur $c): array => [$c->nombre, $c->statut()], $compteurs),
            ),
        );
        self::assertSame(0, static::getContainer()->get(CompteursAnnuels::class)->pour($soiree->getCollective(), (int) $soiree->getDate()->format('Y') + 1)[1]->nombre);
    }

    private function traiterLesMessages(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof SoireeParametresModifies);
        self::assertCount(1, $messages);

        foreach ($messages as $message) {
            $this->generator()($message);
        }
    }

    /**
     * @return array<string, int> days from the soirée by rule code, by due date
     */
    private function echeances(Soiree $soiree): array
    {
        $echeances = [];
        foreach ($this->taches($soiree) as $tache) {
            $echeances[$tache->getCode()] = (int) $soiree->getDate()->diff($tache->getEcheance())->format('%r%a');
        }

        return $echeances;
    }

    /**
     * The Notifier queues its e-mails straight on the async transport, unrendered.
     *
     * @return list<Email>
     */
    private function emails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $emails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage) {
                self::assertInstanceOf(Email::class, $message->getMessage());
                $emails[] = $message->getMessage();
            }
        }

        return $emails;
    }

    /**
     * @return array<string, list<string>> sorted by address
     */
    private function sujetsParDestinataire(): array
    {
        $sujets = [];
        foreach ($this->emails() as $email) {
            $sujets[$email->getTo()[0]->getAddress()][] = (string) $email->getSubject();
        }
        ksort($sujets);

        return $sujets;
    }

    private function texte(int $index): string
    {
        $email = $this->emails()[$index];
        static::getContainer()->get(BodyRendererInterface::class)->render($email);

        return (string) $email->getTextBody();
    }

    private function tache(Soiree $soiree, string $code): TacheAdmin
    {
        return array_values(array_filter($this->taches($soiree), static fn (TacheAdmin $t): bool => $t->getCode() === $code))[0];
    }

    /**
     * @return list<TacheAdmin>
     */
    private function taches(Soiree $soiree): array
    {
        return static::getContainer()->get(TacheAdminRepository::class)->findPourSoiree($soiree);
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function generator(): RetroplanningGenerator
    {
        return static::getContainer()->get(RetroplanningGenerator::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
