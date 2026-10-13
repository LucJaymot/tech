<?php

namespace App\Tests\Notifier;

use App\Entity\AbonnementPush;
use App\Entity\User;
use App\Notifier\WebPushOptions;
use App\Notifier\WebPushTransport;
use App\Obligations\Message\RappelsQuotidiens;
use App\Obligations\Rappels;
use App\Repository\AbonnementPushRepository;
use App\Repository\SoireeRepository;
use App\Repository\UserRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Notifier\Message\PushMessage;

/**
 * Relies on AppFixtures: Théo and Camille are members of the Jardin.
 */
final class WebPushTest extends WebTestCase
{
    use ActsAsMember;
    use ClockSensitiveTrait;

    private const string FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testADeviceSubscribesAndUnsubscribes(): void
    {
        $jeton = $this->login('theo@example.com');

        $this->poster('/notifications/abonnement', ['endpoint' => self::FCM, 'keys' => self::clesAppareil()], $jeton);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['theo@example.com'], array_map(static fn (AbonnementPush $a): string => $a->getUser()->getEmail(), $this->abonnements()->findAll()));

        // Same browser, another account: the device follows the last one.
        $jeton = $this->login('camille@example.com');
        $this->poster('/notifications/abonnement', ['endpoint' => self::FCM, 'keys' => self::clesAppareil()], $jeton);
        self::assertSame(['camille@example.com'], array_map(static fn (AbonnementPush $a): string => $a->getUser()->getEmail(), $this->abonnements()->findAll()));

        $this->poster('/notifications/desabonnement', ['endpoint' => self::FCM], $jeton);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->abonnements()->count([]));
    }

    public function testOnlyKnownPushServicesAreAccepted(): void
    {
        $jeton = $this->login('theo@example.com');

        $this->poster('/notifications/abonnement', ['endpoint' => 'https://169.254.169.254/latest', 'keys' => self::clesAppareil()], $jeton);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->abonnements()->count([]));
    }

    public function testSubscribingNeedsTheCsrfToken(): void
    {
        $this->login('theo@example.com');

        $this->poster('/notifications/abonnement', ['endpoint' => self::FCM, 'keys' => self::clesAppareil()], 'mauvais');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTransportSendsToEveryDeviceAndForgetsExpiredOnes(): void
    {
        $theo = $this->user('theo@example.com');
        foreach ([self::FCM, 'https://updates.push.services.mozilla.com/wpush/v2/expire'] as $endpoint) {
            $cles = self::clesAppareil();
            $this->entityManager()->persist(new AbonnementPush($theo, $endpoint, $cles['p256dh'], $cles['auth'], null));
        }
        $this->entityManager()->flush();

        $requetes = [];
        $http = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$requetes): MockResponse {
            $requetes[$url] = $options['normalized_headers']['authorization'][0] ?? '';

            return new MockResponse('', ['http_code' => str_ends_with($url, 'expire') ? 410 : 201]);
        });
        $cles = VAPID::createVapidKeys();
        $transport = new WebPushTransport($cles['publicKey'], $cles['privateKey'], 'mailto:contact@example.com', $this->abonnements(), $this->entityManager(), new Psr18Client($http));

        $transport->send(new PushMessage('Concert : Sacem', 'À faire aujourd\'hui.', new WebPushOptions('theo@example.com', 'https://caisson.test/soirees/1/retroplanning')));

        self::assertCount(2, $requetes);
        self::assertStringStartsWith('Authorization: vapid t=', $requetes[self::FCM]);
        self::assertSame([self::FCM], array_map(static fn (AbonnementPush $a): string => $a->getEndpoint(), $this->abonnements()->findAll()), 'the expired device is forgotten');
    }

    public function testRemindersArePushedToo(): void
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertNotNull($soiree);
        static::mockTime($soiree->getDate()->modify('-15 days')->format('Y-m-d 07:00'));

        static::getContainer()->get(Rappels::class)->quotidiens(new RappelsQuotidiens());

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $push = array_values(array_filter(array_map(static fn ($e): object => $e->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof PushMessage));
        self::assertCount(6, $push, 'Sacem and bar, for Camille, Nora and Théo');
        $options = $push[0]->getOptions();
        self::assertInstanceOf(WebPushOptions::class, $options);
        self::assertStringEndsWith('/soirees/'.$soiree->getId().'/retroplanning', $options->url);
        self::assertSame('À faire aujourd\'hui.', $push[0]->getContent());
    }

    /**
     * A real P-256 public key and auth secret, as a browser would send.
     *
     * @return array{p256dh: string, auth: string}
     */
    private static function clesAppareil(): array
    {
        $cle = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($cle);
        $details = openssl_pkey_get_details($cle);
        self::assertIsArray($details);
        $ec = $details['ec'];
        $base64url = static fn (string $octets): string => rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');

        return ['p256dh' => $base64url("\x04".$ec['x'].$ec['y']), 'auth' => $base64url(random_bytes(16))];
    }

    /**
     * @param array<string, mixed> $corps
     */
    private function poster(string $url, array $corps, string $jeton): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $jeton], content: json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    /**
     * Logs in and returns the push CSRF token of the session.
     */
    private function login(string $email): string
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $this->entityManager()->clear();

        return (string) $this->client->request('GET', '/collectif')->filter('[data-controller="notifications"]')->attr('data-notifications-jeton-value');
    }

    private function user(string $email): User
    {
        $this->actAs('camille@example.com');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function abonnements(): AbonnementPushRepository
    {
        return static::getContainer()->get(AbonnementPushRepository::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
