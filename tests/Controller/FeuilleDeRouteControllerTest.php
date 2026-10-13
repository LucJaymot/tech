<?php

namespace App\Tests\Controller;

use App\Entity\Affectation;
use App\Entity\Artiste;
use App\Entity\Benevole;
use App\Entity\Booking;
use App\Entity\Creneau;
use App\Entity\Passage;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Enum\ModeRemuneration;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Relies on AppFixtures: "Concert de printemps" (Salle des Lilas, jauge 100),
 * Lila Moon 20:00–21:00, DJ Kumo b2b Nadia K 23:00–01:00, Léa Fontaine
 * (06 12 34 56 78) at the Accueil. Théo is member, Inès reader; Hugo is not
 * in the Jardin.
 */
final class FeuilleDeRouteControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testShowsTimetableAccessTeamContactsAndChecks(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Concert de printemps');
        self::assertSelectorTextContains('#acces', 'Entrée par la cour');
        self::assertSame(
            ['20:00 – 21:00 Lila Moon', '23:00 – 01:00 DJ Kumo b2b Nadia K'],
            $crawler->filter('#timetable li')->each(static fn (Crawler $li): string => trim((string) preg_replace('/\s+/', ' ', $li->text()))),
        );
        self::assertSelectorExists('#equipe a[href="tel:06 12 34 56 78"]');
        self::assertSelectorExists('#contacts-artistes a[href="mailto:kumo@example.com"]');
        self::assertSame(['protections_auditives'], $crawler->filter('#checks li')->each(static fn (Crawler $li): string => str_contains($li->text(), 'protections auditives') ? 'protections_auditives' : $li->text()));
        self::assertSelectorTextContains('#checks', 'vérifiée le 13/10/2026');
        self::assertSelectorExists('link[rel="stylesheet"][media="print"][href*="print"]');
        self::assertSelectorExists('#compte-a-rebours[data-controller="compte-a-rebours"][data-compte-a-rebours-maintenant-value]');
    }

    public function testSoundLevelCheckAbove300People(): void
    {
        $this->login('camille@example.com');
        $soiree = $this->soiree()->setJauge(301);
        $soiree->getLieu()?->setCapacite(500);
        $this->entityManager()->flush();

        $this->client->request('GET', $this->url());

        self::assertSelectorTextContains('#checks', 'enregistrer en continu les niveaux sonores');
    }

    public function testReaderSeesTheSheetWithoutContactDetails(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#equipe', 'Léa Fontaine');
        self::assertSelectorNotExists('a[href^="tel:"]');
        self::assertSelectorNotExists('#contacts-artistes');
    }

    public function testOtherCollectiveGets404(): void
    {
        $url = $this->url();
        $this->login('hugo@example.com');
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testQueryCountDoesNotGrowWithTheLineUpOrTheTeam(): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', $this->url()); // the first request also sets the session up
        $avant = $this->requetes();

        $soiree = $this->soiree();
        $poste = new Poste($soiree, 'Bar');
        $this->entityManager()->persist($poste);
        for ($i = 0; $i < 3; ++$i) {
            $debut = $soiree->getDate()->setTime(21, 10 * $i);
            $passage = new Passage($soiree, $debut, $debut->modify('+5 minutes'));
            $artiste = (new Artiste($soiree->getCollective(), 'Invité '.$i))->setTelephone('07 00 00 00 0'.$i);
            $benevole = (new Benevole($soiree->getCollective(), 'Renfort '.$i))->setTelephone('06 00 00 00 0'.$i);
            $creneau = new Creneau($poste, $debut, $debut->modify('+1 hour'));
            array_map($this->entityManager()->persist(...), [
                $passage, $artiste, $benevole, $creneau,
                (new Booking($soiree, $artiste, 10000, ModeRemuneration::Guso))->setPassage($passage),
                new Affectation($creneau, $benevole),
            ]);
        }
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(5, $crawler->filter('#timetable li'));
        self::assertSelectorTextContains('#equipe', 'Renfort 2 (06 00 00 00 02)');
        self::assertSame($avant, $this->requetes());
    }

    public function testOfflinePageIsPublic(): void
    {
        $this->client->request('GET', '/hors-ligne');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pas de connexion');
    }

    private function requetes(): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $this->entityManager()->clear();
    }

    private function url(): string
    {
        return '/soirees/'.$this->soiree()->getId().'/feuille-de-route';
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
