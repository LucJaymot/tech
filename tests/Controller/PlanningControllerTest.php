<?php

namespace App\Tests\Controller;

use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Relies on AppFixtures: Accueil 18:30–22:00 (2, Léa), Buvette 19:00–22:00
 * (2, Théo) and 22:00–01:00 (2, nobody; Léa and Sam available).
 * Camille is admin, Théo member, Inès reader; Hugo is not in the Jardin.
 */
final class PlanningControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testGapsAreShownWithABlockingLedAndALabel(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#resume-trous [data-led="bloquant"]', '4 personnes manquantes sur 3 créneaux');
        self::assertSelectorTextContains('#resume-trous', 'Bloquant :');
        self::assertSame(
            ['18:30 – 22:00 → Bloquant : 1 manquant', '19:00 – 22:00 → Bloquant : 1 manquant', '22:00 – 01:00 → Bloquant : 2 manquants'],
            $crawler->filter('article[id^="creneau-"]')->each(static fn (Crawler $a): string => \sprintf(
                '%s → %s',
                trim((string) preg_replace('/\s*·.*$/s', '', $a->filter('p')->first()->text())),
                $a->filter('[data-led="bloquant"]')->text(),
            )),
        );
        self::assertSelectorTextContains($this->creneauSelector($crawler, '22:00'), 'Disponibles : Léa Fontaine, Sam');
    }

    public function testFilledCreneauShowsOk(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());
        $this->affecter($crawler, '22:00', 'Sam');
        $crawler = $this->affecter($this->client->request('GET', $this->url()), '22:00', 'Léa Fontaine');

        self::assertSelectorTextContains($this->creneauSelector($crawler, '22:00').' [data-led="ok"]', 'Complet');
        self::assertSelectorTextContains('#resume-trous', '2 personnes manquantes sur 2 créneaux');
    }

    public function testOverlappingAssignmentIsRefusedWithAMessage(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->affecter($this->client->request('GET', $this->url()), '19:00', 'Léa Fontaine');

        self::assertSelectorTextContains('[role="alert"]', 'Léa Fontaine est déjà sur « Accueil »');
        self::assertSelectorTextNotContains($this->creneauSelector($crawler, '19:00').' ul', 'Léa Fontaine');
    }

    public function testMemberAddsPosteCreneauAndVolunteer(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        $crawler = $this->submit($crawler->filter('form[action$="/planning/postes"]')->form(), ['intitule' => 'Son']);
        $poste = $crawler->filter('section[id^="poste-"]')->reduce(static fn (Crawler $s): bool => 'Son' === $s->filter('h2')->text())->first();
        self::assertCount(1, $poste);

        $crawler = $this->submit($poste->selectButton('Ajouter un créneau')->form(), ['debut' => '23:30', 'fin' => '02:00', 'nombre' => '1']);
        self::assertSelectorTextContains('main', 'Créneau ajouté.');
        self::assertSame(
            ['ok' => 1],
            ['ok' => (int) static::getContainer()->get(Connection::class)->fetchOne(
                "SELECT COUNT(*) FROM creneau c JOIN poste p ON p.id = c.poste_id WHERE p.intitule = 'Son' AND c.fin - c.debut = INTERVAL '2 hours 30 minutes'",
            )],
            'The créneau ends at 02:00 the next day.',
        );

        $crawler = $this->submit($crawler->filter('#benevoles form')->form(), ['nom' => 'Nina', 'email' => '']);
        self::assertSelectorTextContains('#benevoles', 'Nina');
    }

    public function testEditorsSeeEachVolunteerPersonalLink(): void
    {
        $this->login('camille@example.com');
        $this->client->request('GET', $this->url());

        self::assertSelectorCount(3, '#benevoles input[readonly]');
        $lien = (string) $this->client->getCrawler()->filter('#benevoles input[readonly]')->first()->attr('value');
        self::assertMatchesRegularExpression('#/benevole/\d+/soiree/\d+\?_expiration=\d+&_hash=#', $lien);
    }

    public function testReaderSeesThePlanningButChangesNothing(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-led="bloquant"]');
        self::assertSelectorNotExists('main form');
        self::assertSelectorNotExists('#benevoles');
    }

    public function testOtherCollectivesAndAnonymousCannotSeeIt(): void
    {
        $url = $this->url();

        $this->login('hugo@example.com');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseRedirects('/connexion');
    }

    private function affecter(Crawler $crawler, string $debut, string $nom): Crawler
    {
        $creneau = $crawler->filter($this->creneauSelector($crawler, $debut));
        $select = $creneau->filter('select[name="benevole"] option')->reduce(static fn (Crawler $o): bool => str_starts_with($o->text(), $nom));
        self::assertCount(1, $select, \sprintf('%s can be picked.', $nom));

        return $this->submit($creneau->selectButton('Affecter')->form(), ['benevole' => (string) $select->attr('value')]);
    }

    /**
     * @param array<string, string> $values
     */
    private function submit(\Symfony\Component\DomCrawler\Form $form, array $values): Crawler
    {
        $this->client->submit($form, $values);
        self::assertResponseRedirects();

        return $this->client->followRedirect();
    }

    private function creneauSelector(Crawler $crawler, string $debut): string
    {
        $ids = $crawler->filter('article[id^="creneau-"]')->reduce(static fn (Crawler $a): bool => str_starts_with($a->filter('p')->first()->text(), $debut))->extract(['id']);
        self::assertCount(1, $ids, \sprintf('One créneau starts at %s.', $debut));

        return '#'.$ids[0];
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    private function url(): string
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return '/soirees/'.$soiree->getId().'/planning';
    }
}
