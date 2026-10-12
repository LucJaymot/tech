<?php

namespace App\Tests\Controller;

use App\Collective\ActiveCollective;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Relies on AppFixtures: Camille is admin of "Jardin partagé des Lilas" (her
 * default collective) and reader of "Atelier vélo solidaire"; Hugo belongs to
 * the Atelier only; Théo is a plain member of the Jardin.
 */
final class InvitationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testNewPersonJoinsWithOneClick(): void
    {
        $link = $this->invite('camille@example.com', 'Marie.Durand@example.com', 'reader');

        $this->client->followRedirect();
        self::assertSelectorTextContains('#invitations', 'marie.durand@example.com');

        // The invitee, on another device.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');
        self::assertSelectorTextContains('main', 'Camille Martin vous invite');
        self::assertSelectorTextContains('main', 'Lecteur');

        $this->client->submit($crawler->selectButton('Rejoindre Jardin partagé des Lilas')->form());
        self::assertResponseRedirects('/collectif');
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');
        self::assertSelectorTextContains('main', 'Bienvenue dans Jardin partagé des Lilas');
        self::assertSelectorTextContains('main', 'Marie Durand');
        self::assertSelectorCount(5, 'main ul.divide-y > li');
        self::assertSelectorNotExists('a[href="/collectif/inviter"]');

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410);
    }

    public function testMemberOfAnotherCollectiveJoinsWithExistingAccount(): void
    {
        $link = $this->invite('camille@example.com', 'hugo@example.com', 'member');

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Rejoindre Jardin partagé des Lilas')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');
        self::assertSelectorTextContains('main', 'hugo@example.com');
        // Hugo now chooses between his two collectives.
        self::assertSelectorCount(2, 'header select[name="collective"] option');
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email = 'hugo@example.com'"));
    }

    public function testLoggedInInviteeJoinsWithoutLoggingInAgain(): void
    {
        $link = $this->invite('camille@example.com', 'hugo@example.com', 'member');

        $this->loginAs('hugo@example.com');
        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Rejoindre Jardin partagé des Lilas')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Jardin partagé des Lilas');
    }

    public function testAnotherAccountCannotAccept(): void
    {
        $link = $this->invite('camille@example.com', 'hugo@example.com', 'member');

        $this->loginAs('theo@example.com');
        $this->client->request('GET', $link);
        self::assertSelectorTextContains('[role="alert"]', 'theo@example.com');
        self::assertSelectorNotExists('main form');

        $this->client->request('POST', $link, ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM membership m JOIN \"user\" u ON u.id = m.user_id JOIN collective c ON c.id = m.collective_id WHERE u.email = 'hugo@example.com' AND c.slug = 'jardin-des-lilas'",
        ));
    }

    public function testOnlyAdminsOfTheActiveCollectiveCanInvite(): void
    {
        $this->loginAs('theo@example.com');
        $this->client->request('GET', '/collectif');
        self::assertSelectorNotExists('a[href="/collectif/inviter"]');
        $this->client->request('GET', '/collectif/inviter');
        self::assertResponseStatusCodeSame(403);

        // Camille is admin of the Jardin but only reader of the Atelier.
        $this->loginAs('camille@example.com');
        $crawler = $this->client->request('GET', '/collectif');
        $this->client->submit($crawler->selectButton('Changer')->form(), [
            'collective' => $this->collectiveId('atelier-velo'),
        ]);
        $this->client->request('GET', '/collectif/inviter');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExistingMemberCannotBeInvited(): void
    {
        $this->loginAs('camille@example.com');
        $crawler = $this->client->request('GET', '/collectif/inviter');
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(), [
            'invitation[email]' => 'nora@example.com',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Cette personne est déjà membre du collectif.');
        self::assertQueuedEmailCount(0);
    }

    public function testInvitingAgainReplacesThePreviousLink(): void
    {
        $first = $this->invite('camille@example.com', 'marie@example.com', 'member');
        $second = $this->invite('camille@example.com', 'marie@example.com', 'admin');

        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM invitation WHERE email = 'marie@example.com'"));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $second);
        self::assertSelectorTextContains('main', 'Administrateur');
    }

    public function testExpiredInvitationIsRejected(): void
    {
        $link = $this->invite('camille@example.com', 'marie@example.com', 'member');
        $this->connection()->executeStatement("UPDATE invitation SET expires_at = NOW() - INTERVAL '1 minute'");

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('main', 'n\'est plus valable');
    }

    public function testAdminCancelsInvitation(): void
    {
        $link = $this->invite('camille@example.com', 'marie@example.com', 'member');
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('#invitations form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invitation de marie@example.com annulée.');
        self::assertSelectorNotExists('#invitations');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvitationsAreIsolatedPerCollective(): void
    {
        $this->invite('camille@example.com', 'marie@example.com', 'member');
        $jardinInvitation = $this->connection()->fetchOne("SELECT id FROM invitation WHERE email = 'marie@example.com'");

        // Nora is admin of the Atelier: switch to it and invite someone there.
        $this->loginAs('nora@example.com');
        $crawler = $this->client->request('GET', '/collectif');
        $this->client->submit($crawler->selectButton('Changer')->form(), [
            'collective' => $this->collectiveId('atelier-velo'),
        ]);
        $crawler = $this->client->request('GET', '/collectif/inviter');
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(), ['invitation[email]' => 'paul@example.com']);
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Atelier vélo solidaire');
        self::assertSelectorTextContains('#invitations', 'paul@example.com');
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('marie@example.com', $content);

        // A valid token does not let her cancel the Jardin's invitation.
        $token = $crawler->filter('#invitations input[name="_token"]')->attr('value');
        $this->client->request('POST', "/collectif/invitations/{$jardinInvitation}/annuler", ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM invitation WHERE email = 'marie@example.com'"));
    }

    /**
     * Sends an invitation as $admin through the form and returns the e-mailed link.
     */
    private function invite(string $admin, string $email, string $role): string
    {
        $this->loginAs($admin);
        $crawler = $this->client->request('GET', '/collectif/inviter');
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(), [
            'invitation[email]' => $email,
            'invitation[role]' => $role,
        ]);
        self::assertResponseRedirects('/collectif');

        self::assertQueuedEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailAddressContains($message, 'To', mb_strtolower($email));
        self::assertEmailTextBodyContains($message, 'Jardin partagé des Lilas');
        $body = $message->getTextBody();
        self::assertIsString($body);
        self::assertSame(1, preg_match('#https?://\S+/invitation/[\w-]+#', $body, $matches));

        return $matches[0];
    }

    private function loginAs(string $email): void
    {
        // The previous request left the collective filter on; each request resets it.
        static::getContainer()->get(ActiveCollective::class)->reset();
        $this->client->getCookieJar()->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $this->client->loginUser($user);
    }

    private function collectiveId(string $slug): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM collective WHERE slug = ?', [$slug]);
        self::assertIsInt($id);

        return (string) $id;
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
