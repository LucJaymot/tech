<?php

namespace App\Tests\Controller;

use App\Entity\Soiree;
use App\Entity\User;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Relies on AppFixtures: "Concert de printemps" (Jardin) in 3 weeks, with its
 * Sacem and bar tasks due in 6 days, the show declaration overdue by 9 days
 * and the Guso one in 5 weeks. Théo is member of the Jardin; Hugo member and
 * Camille reader of the Atelier, which has no soirée.
 */
final class HomeControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testVisitorSeesThePresentationAndNoCollectiveData(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#presentation h1', 'CAISSON');
        self::assertSelectorExists('#presentation img');
        self::assertSelectorExists('#presentation a[href="/connexion"]');
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Concert de printemps', 'Jardin', 'Atelier', 'Sacem'] as $donnee) {
            self::assertStringNotContainsString($donnee, $html);
        }
    }

    public function testMemberSeesTheNextThreeSoireesSoonestFirst(): void
    {
        $concert = $this->soiree();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['+10 days' => 'Avant-première', '+60 days' => 'Hiver', '+90 days' => 'Printemps', '-5 days' => 'Passée'] as $decalage => $nom) {
            $em->persist(new Soiree($concert->getCollective(), $nom, new \DateTimeImmutable($decalage), new \DateTimeImmutable('20:00'), new \DateTimeImmutable('23:00')));
        }
        $em->flush();

        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Avant-première J-10', 'Concert de printemps J-21', 'Hiver J-60'],
            $crawler->filter('#prochaines-soirees li')->each(static fn (Crawler $li): string => $li->filter('a > span:first-child > span:first-child')->text().' '.$li->filter('.font-doto')->text()),
        );
        self::assertSame('/soirees/'.$concert->getId(), $crawler->filter('#prochaines-soirees li')->eq(1)->filter('a')->attr('href'));
    }

    public function testOpenTasksComeOverdueFirstWithTheirLedAndSoiree(): void
    {
        $concert = $this->soiree();

        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/');

        self::assertSame(
            [
                'bloquant Déclarer la représentation (organisateur occasionnel de spectacles)',
                'attention Déclarer la soirée à la Sacem',
                'attention Demander l\'autorisation de buvette temporaire (groupes 1 et 3) en mairie',
                'ok Faire la déclaration unique et simplifiée (Guso) et payer les cotisations',
            ],
            $crawler->filter('#demarches li')->each(static fn (Crawler $li): string => $li->filter('[data-led]')->attr('data-led').' '.$li->filter('span.font-medium')->text()),
        );
        self::assertSelectorTextContains('#demarches li:first-child', 'En retard depuis le');
        self::assertSelectorTextContains('#demarches li:first-child', 'Concert de printemps');
        self::assertSame('/soirees/'.$concert->getId(), $crawler->filter('#demarches li a')->first()->attr('href'));
    }

    public function testDoneTasksAreNotListed(): void
    {
        $concert = $this->soiree();
        foreach (static::getContainer()->get(TacheAdminRepository::class)->findPourSoiree($concert) as $tache) {
            $tache->cocher(new \DateTimeImmutable());
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->login('theo@example.com');
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('#demarches', 'Aucune démarche en attente');
    }

    public function testEmptyCollectiveOffersANewSoireeToMembersOnly(): void
    {
        $this->login('hugo@example.com');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('#prochaines-soirees', 'Aucune soirée à venir');
        self::assertSelectorExists('#prochaines-soirees a#nouvelle-soiree[href="/soirees/nouvelle"]');
        self::assertSelectorTextNotContains('main', 'Concert de printemps', 'nothing from the Jardin');
        self::assertSelectorTextContains('#demarches', 'Aucune démarche en attente');

        $this->login('camille@example.com', 'atelier-velo');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('#prochaines-soirees', 'Aucune soirée à venir');
        self::assertSelectorNotExists('#nouvelle-soiree', 'Camille is reader of the Atelier');
    }

    public function testAccountWithoutCollectiveIsToldToAskForAnInvitation(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('solo@example.com', 'Solo');
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#sans-collectif', 'Vous n\'êtes membre d\'aucun collectif');
        self::assertSelectorTextContains('#sans-collectif', 'vous inviter');
    }

    private function login(string $email, ?string $collectif = null): void
    {
        $user = $this->actAs($email, $collectif);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        if (null !== $collectif) {
            // The session of the browser, not the test's, holds the active collective.
            $crawler = $this->client->request('GET', '/collectif');
            $this->client->submit($crawler->selectButton('Changer')->form(), ['collective' => (string) static::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT id FROM collective WHERE slug = ?', [$collectif])]);
        }
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }
}
