<?php

namespace App\Tests\Controller;

use App\Bilan\BilanSoiree;
use App\Budget\Centimes;
use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Soiree\ResumeSoiree;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relies on AppFixtures: "Concert de printemps" (Jardin) in 3 weeks, 19:00 to
 * 01:00 at the Salle des Lilas, 4 open tasks, 4 volunteers missing on
 * 3 créneaux, 6 guests on 3 lists. Théo is member, Inès reader; Hugo is
 * only in the Atelier.
 */
final class SoireeControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testVisitorIsSentToTheLoginPage(): void
    {
        $url = $this->url();
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/connexion');
    }

    public function testMemberOfAnotherCollectiveGets404(): void
    {
        $url = $this->url();
        $this->login('hugo@example.com');
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function testHeaderShowsTimesVenueStatusAndCountdown(): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#en-tete h1', 'Concert de printemps');
        self::assertSelectorTextContains('#en-tete', '19:00 → 01:00 (lendemain) · Salle des Lilas');
        self::assertSelectorExists('#statut [data-led="attention"]');
        self::assertSelectorTextContains('#statut', 'En préparation', 'a text label, not the colour alone');
        self::assertSelectorTextSame('#compte-a-rebours.compteur', 'J-21');
    }

    public function testBodySummarisesEachModule(): void
    {
        $totaux = static::getContainer()->get(BilanSoiree::class)->pour($this->soiree())['totaux'];

        $this->login('theo@example.com');
        $this->client->request('GET', $this->url());

        self::assertSelectorTextContains('#module-line-up', 'DJ Kumo b2b Nadia K');
        self::assertSelectorTextContains('#module-taches', '4 démarches à faire, dont 1 en retard');
        self::assertSelectorTextNotContains('#module-taches', '{%');
        self::assertSelectorTextContains('#module-benevoles', '4 personnes manquantes sur 3 créneaux');
        self::assertSelectorTextContains('#module-guest-lists', '6 personnes sur 3 listes');
        self::assertSelectorTextContains('#module-budget', Centimes::formater($totaux->soldePrevu()));
        self::assertSelectorExists('#module-guest-lists a[href$="/guest-lists"]');
    }

    public function testReaderSeesThePageWithoutTheMembersOnlyLinks(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#module-guest-lists', '6 personnes');
        self::assertSelectorNotExists('#module-guest-lists a');
        // Readers have VIEW_BUDGET (App\Security\Voter\CollectiveVoter).
        self::assertSelectorExists('#module-budget');
    }

    public function testSoireesPageListsUpcomingThenPastAndLinksEachToItsPage(): void
    {
        $concert = $this->soiree();
        $url = '/soirees/'.$concert->getId();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        foreach (['-10 days' => 'Rentrée', '-40 days' => 'Été', '+60 days' => 'Hiver'] as $decalage => $nom) {
            $em->persist(new Soiree($concert->getCollective(), $nom, new \DateTimeImmutable($decalage), new \DateTimeImmutable('20:00'), new \DateTimeImmutable('23:00')));
        }
        $em->flush();

        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/soirees');

        self::assertResponseIsSuccessful();
        $noms = static fn (string $liste): array => $crawler->filter($liste.' > li a > span:nth-child(2) > span:first-child')->each(static fn ($n): string => $n->text());
        self::assertSame(['Concert de printemps', 'Hiver'], $noms('#soirees'), 'soonest first');
        self::assertSame(['Rentrée', 'Été'], $noms('#soirees-passees'), 'latest first');
        self::assertSame($url, $crawler->filter('#soirees a')->first()->attr('href'));
        self::assertSelectorExists('nav[aria-label="Navigation principale"] a[href="/soirees"][aria-current="page"]');
    }

    public function testSoireeTabStaysLitInsideASoiree(): void
    {
        $url = $this->url();
        $this->login('theo@example.com');

        foreach ([$url, $url.'/planning', $url.'/retroplanning', $url.'/bilan'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorExists('nav[aria-label="Navigation principale"] a[href="/soirees"][aria-current="page"]', $page);
        }
        $this->client->request('GET', '/collectif');
        self::assertSelectorExists('nav[aria-label="Navigation principale"] a[href="/collectif"][aria-current="page"]');
        self::assertSelectorNotExists('nav[aria-label="Navigation principale"] a[href="/a-propos"]');
    }

    public function testPageFollowsTheSoireesLifePrepareTheNightAfterwards(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        self::assertSame(['Préparer', 'Le soir J', 'Après'], $crawler->filter('section > h2.etiquette')->each(static fn ($h): string => $h->text()));
        self::assertSelectorExists('#module-soir-j a[href$="/feuille-de-route"]');
        self::assertSelectorExists('#module-soir-j a[href$="/pointage"]');
        self::assertSelectorExists('#module-line-up a#page-publique[href^="/s/"]');
        self::assertSame('h3', $crawler->filter('#module-taches header > *')->first()->nodeName());
    }

    public function testCountdownCountsCalendarDaysInTheSoireeTimeZone(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $soiree = new \DateTimeImmutable('2027-04-03', $paris);

        self::assertSame('J-12', ResumeSoiree::compteAReboursLe($soiree, new \DateTimeImmutable('2027-03-22 23:30', $paris)));
        self::assertSame('J-12', ResumeSoiree::compteAReboursLe($soiree, new \DateTimeImmutable('2027-03-22 22:30', new \DateTimeZone('UTC'))), 'a UTC clock, 23:30 in Paris');
        self::assertSame('J-7', ResumeSoiree::compteAReboursLe($soiree, new \DateTimeImmutable('2027-03-27 00:10', $paris)), 'across the spring time change');
        self::assertSame('Jour J', ResumeSoiree::compteAReboursLe($soiree, new \DateTimeImmutable('2027-04-03 23:59', $paris)));
        self::assertSame('J+3', ResumeSoiree::compteAReboursLe($soiree, new \DateTimeImmutable('2027-04-06 08:00', $paris)));
    }

    public function testCharterFontsAreSelfHosted(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        $this->client->request('GET', (string) $crawler->filter('link[rel="stylesheet"][href*="app"]')->attr('href'));
        $css = (string) $this->client->getInternalResponse()->getContent();
        foreach (['doto-800-latin', 'space-grotesk-latin', 'ibm-plex-mono-400-latin'] as $police) {
            self::assertMatchesRegularExpression('#url\("?\.\./fonts/'.$police.'-[\w-]+\.woff2"?\)#', $css, $police);
        }
        self::assertStringNotContainsString('fonts.googleapis.com', $css);
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function url(): string
    {
        return '/soirees/'.$this->soiree()->getId();
    }
}
