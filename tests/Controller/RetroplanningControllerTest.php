<?php

namespace App\Tests\Controller;

use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Relies on AppFixtures: the 4 tasks of "Concert de printemps" (Jardin).
 * Théo is member, Inès reader; Hugo is not in the Jardin.
 */
final class RetroplanningControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testShowsTasksWithTheirSourceAndTheIndicativeNotice(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#mention-indicative', 'Ces démarches sont indicatives');
        self::assertCount(4, $crawler->filter('#taches li'));
        self::assertSelectorTextContains('#taches li:first-child', 'Déclarer la représentation');
        self::assertSelectorTextContains('#taches li:first-child', 'vérifiée le 13/10/2026');
        self::assertSame(
            ['1 / 6', '1 / 5', '0 / 6'],
            $crawler->filter('#compteurs [data-led]')->each(static fn (Crawler $led): string => trim(str_replace(['OK :'], '', $led->text()))),
        );
    }

    public function testMemberTicksAndUnticksATask(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        $this->client->submit($crawler->filter('#taches li:first-child form')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#taches li:first-child', 'Fait le '.date('d/m/Y'));

        $this->client->submit($crawler->filter('#taches li:first-child form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('#taches li:first-child', 'Fait le');
    }

    public function testReaderSeesButCannotTick(): void
    {
        $this->login('theo@example.com');
        $action = $this->client->request('GET', $this->url())->filter('#taches li:first-child form')->attr('action');

        $this->login('ines@example.com');
        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#taches form'));

        // Readers get no token for these forms; the voter (SoireeVoter::EDIT) is the second lock.
        $this->client->request('POST', (string) $action, ['_token' => 'irrelevant']);
        self::assertFalse($this->client->getResponse()->isSuccessful());
        $this->client->request('GET', $this->url());
        self::assertSelectorTextNotContains('#taches li:first-child', 'Fait le');
    }

    public function testOtherCollectiveGets404(): void
    {
        $url = $this->url();
        $this->login('hugo@example.com');
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
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

        return '/soirees/'.$soiree->getId().'/retroplanning';
    }
}
