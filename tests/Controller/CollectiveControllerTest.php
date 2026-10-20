<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * Relies on AppFixtures, see App\Tests\Collective\CollectiveIsolationTest.
 */
final class CollectiveControllerTest extends WebTestCase
{
    public function testMemberOfAOnlySeesMembersOfA(): void
    {
        $client = $this->loggedInClient('theo@example.com');
        $client->request('GET', '/collectif');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');
        self::assertSelectorTextContains('main', 'Inès Moreau');
        self::assertSelectorCount(4, '#membres > li');
        self::assertSelectorNotExists('form[action="/collectif/actif"]');

        $html = $this->html($client);
        self::assertStringNotContainsString('Atelier vélo', $html);
        self::assertStringNotContainsString('Hugo', $html);
        self::assertStringNotContainsString('hugo@example.com', $html);
    }

    public function testSwitchingCollectiveShowsOnlyTheNewOne(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');
        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');

        $client->submit($crawler->selectButton('Changer')->form(), [
            'collective' => (string) $this->collectiveId('atelier-velo'),
        ]);

        self::assertResponseRedirects('/collectif');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Atelier vélo solidaire');
        self::assertSelectorTextContains('main', 'Hugo Petit');
        self::assertSelectorCount(3, '#membres > li');
        self::assertStringNotContainsString('Théo', $this->html($client));
    }

    public function testHeaderShowsActiveCollectiveOnEveryPage(): void
    {
        $client = $this->loggedInClient('theo@example.com');
        $client->request('GET', '/a-propos');

        // Single collective: its name, no selector.
        self::assertSelectorTextContains('header', 'Jardin partagé des Lilas');
        self::assertSelectorNotExists('header select');

        $camille = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'camille@example.com']);
        self::assertNotNull($camille);
        $client->loginUser($camille);
        $client->request('GET', '/');
        self::assertSelectorExists('header select[name="collective"]');
        self::assertSelectorTextContains('header option[selected]', 'Jardin partagé des Lilas');
        self::assertSelectorCount(2, 'header option');
    }

    public function testSwitchingFromHeaderReturnsToTheSamePage(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/a-propos');

        $client->submit($crawler->selectButton('Changer')->form(), [
            'collective' => (string) $this->collectiveId('atelier-velo'),
        ]);
        self::assertResponseRedirects('/a-propos');

        $client->followRedirect();
        self::assertSelectorTextContains('header option[selected]', 'Atelier vélo solidaire');
        self::assertSelectorTextContains('main', 'Collectif actif : Atelier vélo solidaire.');
    }

    public function testSwitchingNeverRedirectsOutsideTheSite(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/');
        $token = $crawler->filter('header input[name="_token"]')->attr('value');

        foreach (['//evil.example', '/\\evil.example', 'https://evil.example', ''] as $target) {
            $client->request('POST', '/collectif/actif', [
                '_token' => $token,
                'collective' => $this->collectiveId('jardin-des-lilas'),
                '_target_path' => $target,
            ]);
            self::assertResponseRedirects('/collectif');
        }
    }

    public function testSwitchingToUnknownCollectiveIsForbidden(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');

        $client->request('POST', '/collectif/actif', [
            '_token' => $crawler->filter('header input[name="_token"]')->attr('value'),
            'collective' => 999999,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminChangesARoleAndRemovesAMember(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');

        $client->submit($crawler->filter('form[action="/collectif/membres/'.$this->membershipId('theo', 'jardin-des-lilas').'/role"]')->form(), ['role' => 'reader']);
        self::assertResponseRedirects('/collectif#membres');
        self::assertSame('reader', $this->role('theo', 'jardin-des-lilas'));

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Théo Lambert est maintenant lecteur.');
        $client->submit($this->formulaireRetirer($crawler, 'nora'));
        self::assertResponseRedirects('/collectif#membres');
        self::assertNull($this->role('nora', 'jardin-des-lilas'));
        self::assertSame('admin', $this->role('nora', 'atelier-velo'), 'her other collective is untouched');

        $client->followRedirect();
        self::assertSelectorCount(3, '#membres > li');
    }

    public function testTheLastAdminCanNeitherBeDemotedNorLeave(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');
        $camille = $this->membershipId('camille', 'jardin-des-lilas');

        $client->submit($crawler->filter('form[action="/collectif/membres/'.$camille.'/role"]')->form(), ['role' => 'member']);
        self::assertResponseRedirects('/collectif#membres');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Le collectif doit garder au moins un administrateur.');

        $client->submit($this->formulaireRetirer($crawler, 'camille'));
        self::assertResponseRedirects('/collectif#membres');
        self::assertSame('admin', $this->role('camille', 'jardin-des-lilas'));

        // With a second admin, she can step down.
        $client->submit($client->followRedirect()->filter('form[action="/collectif/membres/'.$this->membershipId('nora', 'jardin-des-lilas').'/role"]')->form(), ['role' => 'admin']);
        $client->submit($client->followRedirect()->filter('form[action="/collectif/membres/'.$camille.'/role"]')->form(), ['role' => 'member']);
        self::assertSame('member', $this->role('camille', 'jardin-des-lilas'));
    }

    public function testAMemberCanLeaveButNotManageOthers(): void
    {
        $client = $this->loggedInClient('theo@example.com');
        $crawler = $client->request('GET', '/collectif');
        self::assertSelectorNotExists('#membres select');
        self::assertSelectorCount(1, '#membres form');
        $token = $crawler->filter('#membres input[name="_token"]')->attr('value');

        $client->request('POST', '/collectif/membres/'.$this->membershipId('ines', 'jardin-des-lilas').'/role', ['_token' => $token, 'role' => 'admin']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/collectif/membres/'.$this->membershipId('ines', 'jardin-des-lilas').'/retirer', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('reader', $this->role('ines', 'jardin-des-lilas'));

        $client->submit($this->formulaireRetirer($crawler, 'theo'));
        self::assertResponseRedirects('/');
        self::assertNull($this->role('theo', 'jardin-des-lilas'));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Vous n\'êtes membre d\'aucun collectif');
    }

    public function testMembersOfAnotherCollectiveAreNotFound(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');
        $token = $crawler->filter('#membres input[name="_token"]')->attr('value');

        // Camille is admin of the Lilas, active here; Hugo is in the Atelier vélo.
        $client->request('POST', '/collectif/membres/'.$this->membershipId('hugo', 'atelier-velo').'/retirer', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('member', $this->role('hugo', 'atelier-velo'));
    }

    public function testAUserRenamesThenDeletesTheirAccount(): void
    {
        $client = $this->loggedInClient('theo@example.com');
        $crawler = $client->request('GET', '/collectif');

        $client->submit($crawler->selectButton('Enregistrer')->form(), ['nom' => '  ']);
        self::assertResponseRedirects('/collectif#compte');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Le nom ne peut pas être vide.');

        $client->submit($crawler->selectButton('Enregistrer')->form(), ['nom' => 'Théo D.']);
        $crawler = $client->followRedirect();
        self::assertInputValueSame('nom', 'Théo D.');

        $client->submit($crawler->selectButton('Supprimer mon compte')->form());
        self::assertResponseRedirects('/collectif#compte');
        self::assertSame(1, $this->comptes('theo@example.com'), 'not confirmed');

        $crawler = $client->followRedirect();
        $client->submit($crawler->selectButton('Supprimer mon compte')->form(), ['confirmer' => '1']);
        self::assertResponseRedirects('/connexion');
        self::assertSame(0, $this->comptes('theo@example.com'));
        self::assertNull($this->role('theo', 'jardin-des-lilas'));

        $client->request('GET', '/collectif');
        self::assertResponseRedirects('/connexion', message: 'logged out');
    }

    public function testTheLastAdminCannotDeleteTheirAccount(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');

        $client->submit($crawler->selectButton('Supprimer mon compte')->form(), ['confirmer' => '1']);

        self::assertResponseRedirects('/collectif#compte');
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Nommez un autre administrateur de Jardin partagé des Lilas');
        self::assertSame(1, $this->comptes('camille@example.com'));
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/collectif');

        self::assertResponseRedirects('/connexion');
    }

    private function loggedInClient(string $email): KernelBrowser
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);

        return $client;
    }

    private function html(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    private function formulaireRetirer(Crawler $crawler, string $user): Form
    {
        return $crawler->filter('form[action="/collectif/membres/'.$this->membershipId($user, 'jardin-des-lilas').'/retirer"]')->form();
    }

    private function membershipId(string $user, string $slug): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT m.id FROM membership m JOIN "user" u ON u.id = m.user_id JOIN collective c ON c.id = m.collective_id WHERE u.email = ? AND c.slug = ?',
            [$user.'@example.com', $slug],
        );
    }

    private function role(string $user, string $slug): ?string
    {
        $role = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT m.role FROM membership m JOIN "user" u ON u.id = m.user_id JOIN collective c ON c.id = m.collective_id WHERE u.email = ? AND c.slug = ?',
            [$user.'@example.com', $slug],
        );

        return false === $role ? null : $role;
    }

    private function comptes(string $email): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$email]);
    }

    private function collectiveId(string $slug): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT id FROM collective WHERE slug = ?', [$slug]);
    }
}
