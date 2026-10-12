<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;

/**
 * Relies on AppFixtures (camille@example.com exists).
 */
final class SecurityControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        // Rate limiters and used login links live in cache pools that outlive a test.
        self::bootKernel();
        static::getContainer()->get('cache.rate_limiter')->clear();
        static::getContainer()->get('cache.app')->clear();
        self::ensureKernelShutdown();
    }

    public function testLoginWithMagicLink(): void
    {
        $client = static::createClient();
        // Keep the array cache (used links, rate limiters) across requests.
        $client->disableReboot();

        $this->requestLink($client, 'Camille@Example.com ');
        self::assertResponseRedirects('/connexion/envoye');
        self::assertQueuedEmailCount(1);

        $email = $this->lastEmail();
        self::assertEmailAddressContains($email, 'To', 'camille@example.com');
        self::assertEmailHtmlBodyContains($email, 'Me connecter');
        $link = $this->extractLink($email);

        // GET only shows a confirmation page, so prefetching scanners don't log in.
        $crawler = $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertNull($this->rememberMeCookie($client));

        $client->submit($crawler->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/collectif');

        $rememberMe = $this->rememberMeCookie($client);
        self::assertNotNull($rememberMe);
        self::assertGreaterThan(time() + 29 * 86400, (int) $rememberMe->getExpiresTime());

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');
    }

    public function testLinkWorksOnlyOnce(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->requestLink($client, 'camille@example.com');
        $link = $this->extractLink($this->lastEmail());

        $client->submit($client->request('GET', $link)->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/collectif');

        $client->getCookieJar()->clear();
        $client->submit($client->request('GET', $link)->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'invalide, expiré ou a déjà servi');
    }

    public function testTamperedLinkIsRejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->requestLink($client, 'camille@example.com');
        $link = preg_replace('/hash=[^&]+/', 'hash=forged', $this->extractLink($this->lastEmail()));
        self::assertIsString($link);

        $client->submit($client->request('GET', $link)->selectButton('Me connecter')->form());
        self::assertResponseRedirects('/connexion');
        self::assertNull($this->rememberMeCookie($client));
    }

    public function testUnknownAddressGetsSameAnswerWithoutEmail(): void
    {
        $client = static::createClient();
        $this->requestLink($client, 'personne@example.com');

        self::assertResponseRedirects('/connexion/envoye');
        self::assertQueuedEmailCount(0);
    }

    public function testRequestsAreRateLimitedPerAddress(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 3; ++$i) {
            $this->requestLink($client, 'camille@example.com');
            self::assertResponseRedirects('/connexion/envoye');
        }

        $this->requestLink($client, 'camille@example.com');
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('main', 'Trop de demandes de connexion');
        self::assertQueuedEmailCount(0);
    }

    public function testRequestsAreRateLimitedPerIp(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 10; ++$i) {
            $this->requestLink($client, "inconnu{$i}@example.com");
            self::assertResponseRedirects('/connexion/envoye');
        }

        $this->requestLink($client, 'camille@example.com');
        self::assertResponseStatusCodeSame(429);
        self::assertQueuedEmailCount(0);
    }

    private function requestLink(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/connexion');
        $client->submit($crawler->selectButton('Recevoir mon lien')->form(), [
            'login_link_request[email]' => $email,
        ]);
    }

    private function lastEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function extractLink(Email $email): string
    {
        $body = $email->getTextBody();
        self::assertIsString($body);
        self::assertSame(1, preg_match('#https?://\S+/connexion/verification\?\S+#', $body, $matches));

        return $matches[0];
    }

    /**
     * @phpstan-impure the cookie jar changes with each request
     */
    private function rememberMeCookie(KernelBrowser $client): ?Cookie
    {
        return $client->getCookieJar()->get('REMEMBERME');
    }
}
