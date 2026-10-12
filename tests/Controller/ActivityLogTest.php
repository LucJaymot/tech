<?php

namespace App\Tests\Controller;

use App\Collective\ActiveCollective;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * The activity log records who did what and when, through real HTTP flows.
 * Relies on AppFixtures: Camille is admin of "Jardin partagé des Lilas".
 */
final class ActivityLogTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $lastActivityId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->lastActivityId = (int) $this->connection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM activity');
    }

    public function testInvitationJourneyIsLoggedWithItsActors(): void
    {
        $link = $this->invite('marie.durand@example.com');

        self::assertSame([
            ['Invitation', 'created', 'camille@example.com', []],
        ], $this->newActivities());

        // Marie accepts from another device, without an account.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Rejoindre Jardin partagé des Lilas')->form());
        self::assertResponseRedirects('/collectif');

        self::assertSame([
            ['Invitation', 'created', 'camille@example.com', []],
            ['Membership', 'created', 'marie.durand@example.com', []],
            ['Invitation', 'updated', 'marie.durand@example.com', ['acceptedAt']],
        ], $this->newActivities());
    }

    public function testCancellationIsLogged(): void
    {
        $this->invite('marie@example.com');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('#invitations form')->form());

        self::assertSame(['Invitation', 'deleted', 'camille@example.com', []], $this->newActivities()[1]);
    }

    public function testLogHoldsNoPersonalData(): void
    {
        $link = $this->invite('marie.durand@example.com');
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Rejoindre Jardin partagé des Lilas')->form());

        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM activity WHERE id > ?', [$this->lastActivityId]);
        self::assertCount(3, $rows);

        $dump = json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        foreach (['@', 'marie', 'Marie', 'Durand', 'Camille', 'Martin', 'Jardin'] as $personal) {
            self::assertStringNotContainsString($personal, $dump);
        }
        self::assertSame(
            ['id', 'action', 'subject_type', 'subject_id', 'changed_fields', 'occurred_at', 'collective_id', 'actor_id'],
            array_keys($rows[0]),
        );
    }

    /**
     * @return list<array{string, string, ?string, list<string>}> subject type, action, actor e-mail and changed fields of the activities logged by the test
     */
    private function newActivities(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT a.subject_type, a.action, u.email, a.changed_fields FROM activity a LEFT JOIN "user" u ON u.id = a.actor_id WHERE a.id > ? ORDER BY a.id',
            [$this->lastActivityId],
        );

        return array_map(static function (array $row): array {
            self::assertIsString($row['subject_type']);
            self::assertIsString($row['action']);
            self::assertTrue(null === $row['email'] || \is_string($row['email']));
            self::assertIsString($row['changed_fields']);
            $fields = json_decode($row['changed_fields'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($fields);
            self::assertTrue(array_is_list($fields));
            self::assertContainsOnlyString($fields);

            return [$row['subject_type'], $row['action'], $row['email'], $fields];
        }, $rows);
    }

    private function invite(string $email): string
    {
        static::getContainer()->get(ActiveCollective::class)->reset();
        $camille = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'camille@example.com']);
        self::assertNotNull($camille);
        $this->client->loginUser($camille);

        $crawler = $this->client->request('GET', '/collectif/inviter');
        $this->client->submit($crawler->selectButton('Envoyer l\'invitation')->form(), ['invitation[email]' => $email]);
        self::assertResponseRedirects('/collectif');

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        $body = $message->getTextBody();
        self::assertIsString($body);
        self::assertSame(1, preg_match('#https?://\S+/invitation/[\w-]+#', $body, $matches));

        return $matches[0];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
