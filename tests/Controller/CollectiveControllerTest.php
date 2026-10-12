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
        self::assertSelectorCount(4, 'main li');
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

        $client->submit($crawler->selectButton('Activer ce collectif')->form(), [
            'collective' => (string) $this->collectiveId('atelier-velo'),
        ]);

        self::assertResponseRedirects('/collectif');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Atelier vélo solidaire');
        self::assertSelectorTextContains('main', 'Hugo Petit');
        self::assertSelectorCount(3, 'main ul.divide-y > li');
        self::assertStringNotContainsString('Théo', $this->html($client));
    }

    public function testSwitchingToUnknownCollectiveIsForbidden(): void
    {
        $client = $this->loggedInClient('camille@example.com');
        $crawler = $client->request('GET', '/collectif');

        $client->request('POST', '/collectif/actif', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
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
