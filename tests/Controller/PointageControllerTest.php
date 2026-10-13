<?php

namespace App\Tests\Controller;

use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relies on AppFixtures: 4 guests on "Concert de printemps" (Amélie Dupré +1,
 * Zoé Lambert, Hélène Pétillon +1, Joël Œuvray), none checked in.
 * Camille is admin, Théo member, Inès reader; Hugo is not in the Jardin.
 */
final class PointageControllerTest extends WebTestCase
{
    use ActsAsMember;

    private const string U1 = '6f1c1c43-0d0e-4b8e-9a3e-0c2a8e3d9b51';
    private const string U2 = '7a2d2d54-1e1f-4c9f-8b4f-1d3b9f4eac62';

    private KernelBrowser $client;
    private string $csrf;
    private ?\DateTimeImmutable $maintenant = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testDoorScreenIsFullScreenAndWiredToTheEndpoints(): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="pointage"][data-pointage-liste-url-value$="/pointage/invites"][data-pointage-synchro-url-value$="/pointage/synchronisation"]');
        self::assertSelectorExists('input[type="search"][data-action="input->pointage#rechercher"].text-3xl');
        self::assertSelectorNotExists('nav');
        self::assertSelectorNotExists('header');
    }

    public function testDevicesDownloadTheGuestList(): void
    {
        $invites = $this->invites();

        self::assertSame(['Amélie Dupré', 'Hélène Pétillon', 'Joël Œuvray', 'Zoé Lambert'], array_column($invites, 'nom'));
        self::assertSame(['id', 'nom', 'accompagnants', 'liste', 'pointe_at', 'pointage_id'], array_keys($invites[0]));
        self::assertSame(['Lila Moon', 1], [$invites[0]['liste'], $invites[0]['accompagnants']]);
        self::assertSame('Camille Martin', $invites[2]['liste']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCheckInIsSavedOnceEvenWhenSentTwice(): void
    {
        $helene = $this->inviteId('Hélène Pétillon');
        $pointage = ['id' => self::U1, 'invite' => $helene, 'at' => $this->at(10)];

        self::assertSame('accepte', $this->sync([$pointage])[0]['resultat']);
        // The answer was lost: the device sends it again.
        $resultat = $this->sync([$pointage])[0];

        self::assertSame('deja_enregistre', $resultat['resultat']);
        self::assertSame($this->at(10), $resultat['pointe_at']);
        self::assertSame('Théo Lambert', $resultat['pointe_par']);
        self::assertSame([$this->heure(10), self::U1, 'Théo Lambert'], $this->pointageEnBase($helene));
    }

    public function testEarliestCheckInWinsAcrossDevices(): void
    {
        $joel = $this->inviteId('Joël Œuvray');
        $this->sync([['id' => self::U1, 'invite' => $joel, 'at' => $this->at(30)]]);

        // A second device checked him in 3 minutes later: it loses and learns the winning time.
        $conflit = $this->sync([['id' => self::U2, 'invite' => $joel, 'at' => $this->at(27)]])[0];
        self::assertSame('conflit', $conflit['resultat']);
        self::assertSame($this->at(30), $conflit['pointe_at']);
        self::assertSame([$this->heure(30), self::U1, 'Théo Lambert'], $this->pointageEnBase($joel));

        // A third device, offline since 5 minutes earlier, comes back: its earlier check-in wins.
        $plusTot = $this->sync([['id' => 'ef0e0e65-2f20-4da0-9c50-2e4c0a5fbd73', 'invite' => $joel, 'at' => $this->at(35)]], 'camille@example.com')[0];
        self::assertSame('accepte', $plusTot['resultat']);
        self::assertSame([$this->heure(35), 'ef0e0e65-2f20-4da0-9c50-2e4c0a5fbd73', 'Camille Martin'], $this->pointageEnBase($joel));
    }

    public function testInvalidCheckInsAreReportedOneByOne(): void
    {
        $zoe = $this->inviteId('Zoé Lambert');
        $resultats = $this->sync([
            ['id' => 'pas-un-uuid', 'invite' => $zoe, 'at' => $this->at(5)],
            ['id' => self::U1, 'invite' => 999999, 'at' => $this->at(5)],
            ['id' => self::U1, 'invite' => $zoe, 'at' => 'hier soir'],
            ['id' => self::U1, 'invite' => $zoe, 'at' => $this->at(-60)],
            'pas un objet',
            ['id' => self::U2, 'invite' => $zoe, 'at' => $this->at(5)],
        ]);

        self::assertSame(['invalide', 'invalide', 'invalide', 'invalide', 'invalide', 'accepte'], array_column($resultats, 'resultat'));
    }

    public function testCheckInIdCannotBeReusedForAnotherGuest(): void
    {
        $this->sync([['id' => self::U1, 'invite' => $this->inviteId('Zoé Lambert'), 'at' => $this->at(5)]]);

        $resultat = $this->sync([['id' => self::U1, 'invite' => $this->inviteId('Joël Œuvray'), 'at' => $this->at(4)]])[0];

        self::assertSame('invalide', $resultat['resultat']);
        self::assertNull($this->pointageEnBase($this->inviteId('Joël Œuvray'))[0]);
    }

    public function testCheckedInGuestsShowInTheDownloadedList(): void
    {
        $this->sync([['id' => self::U1, 'invite' => $this->inviteId('Zoé Lambert'), 'at' => $this->at(5)]]);

        $zoe = array_values(array_filter($this->invites(), static fn (array $i): bool => 'Zoé Lambert' === $i['nom']))[0];
        self::assertSame(self::U1, $zoe['pointage_id']);
        self::assertSame($this->at(5), $zoe['pointe_at']);
    }

    public function testSynchronisationIsProtected(): void
    {
        $this->login('theo@example.com');
        $this->client->request('POST', $this->url().'/synchronisation', server: ['CONTENT_TYPE' => 'application/json'], content: '{"pointages": []}');
        self::assertResponseStatusCodeSame(400, 'CSRF token required.');

        $this->client->request('POST', $this->url().'/synchronisation', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], content: '{"pointages": "non"}');
        self::assertResponseStatusCodeSame(400);

        $this->login('ines@example.com');
        $this->client->request('GET', $this->url().'/invites');
        self::assertResponseStatusCodeSame(403, 'Readers do not check guests in.');

        $this->login('hugo@example.com');
        $this->client->request('GET', $this->url().'/invites');
        self::assertResponseStatusCodeSame(404);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->url().'/invites');
        self::assertResponseRedirects('/connexion');
    }

    /**
     * @param list<mixed> $pointages
     *
     * @return list<array{id: string, invite: int, resultat: string, pointe_at: ?string, pointe_par: ?string}>
     *
     * @phpstan-impure each call is a request
     */
    private function sync(array $pointages, string $email = 'theo@example.com'): array
    {
        $this->login($email);
        $this->client->request('POST', $this->url().'/synchronisation', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ], content: json_encode(['pointages' => $pointages], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['resultats'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invites(): array
    {
        $this->login('theo@example.com');
        $this->client->request('GET', $this->url().'/invites');
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['invites'];
    }

    /**
     * @return array{?string, ?string, ?string} time, check-in id and who checked in
     */
    private function pointageEnBase(int $invite): array
    {
        $row = static::getContainer()->get(Connection::class)->fetchAssociative(
            "SELECT to_char(i.pointe_at, 'HH24:MI:SS') AS at, i.pointage_id, u.display_name FROM invite i LEFT JOIN \"user\" u ON u.id = i.pointe_par_id WHERE i.id = ?",
            [$invite],
        );
        self::assertIsArray($row);

        return [$row['at'], $row['pointage_id'], $row['display_name']];
    }

    private function inviteId(string $nom): int
    {
        $id = static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM invite WHERE nom = ?', [$nom]);
        self::assertIsInt($id);

        return $id;
    }

    /**
     * A check-in time $minutes before now, to the second, as a device sends it.
     */
    private function at(int $minutes): string
    {
        return $this->instant($minutes)->format(\DATE_ATOM);
    }

    private function heure(int $minutes): string
    {
        return $this->instant($minutes)->format('H:i:s');
    }

    private function instant(int $minutes): \DateTimeImmutable
    {
        $this->maintenant ??= new \DateTimeImmutable('-1 second')->setTime((int) date('H'), (int) date('i'), (int) date('s'));

        return $this->maintenant->modify(\sprintf('-%d minutes', $minutes));
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $crawler = $this->client->request('GET', '/soirees/'.$this->soiree()->getId().'/pointage');
        $this->csrf = (string) ($crawler->filter('[data-pointage-csrf-value]')->count() ? $crawler->filter('[data-pointage-csrf-value]')->attr('data-pointage-csrf-value') : '');
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    private function url(): string
    {
        return '/soirees/'.$this->soiree()->getId().'/pointage';
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }
}
