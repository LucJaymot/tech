<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    private function collectiveId(string $slug): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT id FROM collective WHERE slug = ?', [$slug]);
    }
}
