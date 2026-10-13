<?php

namespace App\Tests\Controller;

use App\Booking\FeuillesArtistes;
use App\Collective\ActiveCollective;
use App\Entity\Booking;
use App\Entity\Soiree;
use App\GuestList\LiensGuestList;
use App\Repository\BookingRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Relies on AppFixtures: Lila Moon's list (quota 4) has Amélie Dupré +1 and
 * Zoé Lambert; Camille's list (quota 2) has Joël Œuvray.
 */
final class GuestListControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testArtistFillsTheirListFromTheSignedLink(): void
    {
        $lien = $this->lien('Lila Moon');
        $crawler = $this->client->request('GET', $lien);

        self::assertResponseIsSuccessful();
        self::assertNull(static::getContainer()->get(TokenStorageInterface::class)->getToken());
        self::assertSelectorTextContains('main', '3 / 4 personnes');
        self::assertSelectorTextContains('[data-guest-list]', 'Amélie Dupré +1');
        self::assertSelectorTextContains('[data-ajout-invite]', '1 place restante');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $this->client->submit($crawler->filter('[data-ajout-invite]')->form(), ['nom' => 'Marc', 'accompagnants' => '0']);
        self::assertResponseRedirects($lien);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Marc ajouté·e.');
        self::assertSelectorTextContains('main', '4 / 4 personnes');
    }

    public function testQuotaIsEnforcedByTheServer(): void
    {
        $lien = $this->lien('Lila Moon');
        $crawler = $this->client->request('GET', $lien);
        $token = $crawler->filter('[data-ajout-invite] input[name="_token"]')->attr('value');

        // Whatever the form allows, the server counts.
        $this->client->request('POST', $lien, ['_token' => $token, 'nom' => 'Toute la famille', 'accompagnants' => '9']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'Plus assez de places : 1 restante sur 4.');
        self::assertSame(2, $this->invitesDe('Lila Moon'));
    }

    public function testArtistRemovesOnlyTheirOwnGuests(): void
    {
        $lien = $this->lien('Lila Moon');
        $crawler = $this->client->request('GET', $lien);
        $token = $crawler->filter('[data-ajout-invite] input[name="_token"]')->attr('value');
        $joel = (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT id FROM invite WHERE nom = 'Joël Œuvray'");

        $this->client->request('POST', $lien, ['_token' => $token, 'retirer' => (string) $joel]);
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM invite WHERE id = ?', [$joel]), "Camille's guest stays.");

        $this->client->submit($this->client->request('GET', $lien)->filter('[data-guest-list] form')->first()->form());
        self::assertSame(1, $this->invitesDe('Lila Moon'));
    }

    public function testForgedOrExpiredLinksAndTokensAreRefused(): void
    {
        $lien = $this->lien('Lila Moon');

        $this->client->request('GET', (string) preg_replace('/_hash=[^&]+/', '_hash=forged', $lien));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $lien, ['_token' => 'forged', 'nom' => 'X']);
        self::assertResponseStatusCodeSame(400);

        $url = (string) preg_replace('/\?.*$/', '', $lien);
        $this->client->request('GET', static::getContainer()->get(UriSigner::class)->sign($url, new \DateTimeImmutable('-1 minute')));
        self::assertResponseStatusCodeSame(410);
    }

    public function testListIsFrozenOnceTheSoireeIsOver(): void
    {
        $lien = $this->lien('Lila Moon');
        $token = $this->client->request('GET', $lien)->filter('[data-ajout-invite] input[name="_token"]')->attr('value');
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE soiree SET statut = 'terminee'");
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', $lien);
        self::assertSelectorNotExists('[data-ajout-invite]');
        self::assertSelectorTextContains('main', 'La soirée est passée');

        $this->client->request('POST', $lien, ['_token' => $token, 'nom' => 'Retardataire']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testArtistSheetLinksToTheGuestList(): void
    {
        $this->actAs('camille@example.com');
        $feuille = static::getContainer()->get(FeuillesArtistes::class)->lien($this->booking('Lila Moon'));
        $this->anonyme();

        $crawler = $this->client->request('GET', $feuille);
        $lien = (string) $crawler->filter('#lien-guest-list')->attr('href');
        $this->client->request('GET', $lien);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Guest list · Lila Moon');
    }

    public function testMemberManagesTheirOwnList(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#ma-liste', '0 / 2 personnes');
        self::assertSelectorNotExists('#toutes-les-listes');

        $this->client->submit($crawler->filter('#ma-liste [data-ajout-invite]')->form(), ['nom' => 'Inès Dumas', 'accompagnants' => '1']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#ma-liste', 'Inès Dumas +1');

        $this->client->submit($crawler->filter('#ma-liste [data-guest-list] form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#ma-liste', 'Personne pour l\'instant.');
    }

    public function testOnlyAdminsSeeAllListsAndSetQuotas(): void
    {
        $this->login('camille@example.com');
        $crawler = $this->client->request('GET', $this->url());
        self::assertSelectorCount(3, '#toutes-les-listes section', 'Lila, Kumo and Camille herself.');

        $form = $crawler->filter('#toutes-les-listes form[action$="/quota"]')->first()->form();
        $this->client->submit($form, ['quota' => '6']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Quota de Lila Moon : 6.');

        $listeLila = (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT g.id FROM guest_list g JOIN booking b ON b.id = g.booking_id JOIN artiste a ON a.id = b.artiste_id WHERE a.nom = ?', ['Lila Moon']);
        $this->login('theo@example.com');
        $token = $this->client->request('GET', $this->url())->filter('#ma-liste [data-ajout-invite] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/guest-lists/'.$listeLila.'/quota', ['_token' => $token, 'quota' => '50']);
        self::assertResponseStatusCodeSame(403);

        $joel = (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT id FROM invite WHERE nom = 'Joël Œuvray'");
        $this->client->request('POST', '/guest-lists/invites/'.$joel.'/retirer', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403, "Théo cannot remove Camille's guest.");
    }

    public function testReadersHaveNoGuestList(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    private function invitesDe(string $artiste): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM invite i JOIN guest_list g ON g.id = i.guest_list_id JOIN booking b ON b.id = g.booking_id JOIN artiste a ON a.id = b.artiste_id WHERE a.nom = ?',
            [$artiste],
        );
    }

    private function lien(string $artiste): string
    {
        $this->actAs('camille@example.com');
        $lien = static::getContainer()->get(LiensGuestList::class)->lien($this->booking($artiste));
        $this->anonyme();

        return $lien;
    }

    private function anonyme(): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        static::getContainer()->get(ActiveCollective::class)->reset();
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function booking(string $artiste): Booking
    {
        foreach (static::getContainer()->get(BookingRepository::class)->findAll() as $booking) {
            if ($artiste === $booking->getArtiste()->getNom()) {
                return $booking;
            }
        }
        self::fail(\sprintf('%s is booked in the fixtures.', $artiste));
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function url(): string
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return '/soirees/'.$soiree->getId().'/guest-lists';
    }
}
