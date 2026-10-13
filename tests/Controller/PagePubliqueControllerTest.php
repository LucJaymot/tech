<?php

namespace App\Tests\Controller;

use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use App\Visuel\VisuelsLineUp;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relies on AppFixtures: "Concert de printemps" (Salle des Lilas, access notes,
 * budget, guest lists, DJ Kumo's e-mail kumo@example.com).
 */
final class PagePubliqueControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $storage = static::getContainer()->get('visuels.storage');
        $storage->deleteDirectory('lineups');
    }

    public function testAnonymousVisitorSeesTheLineUpAndNothingPrivate(): void
    {
        $slug = $this->soiree()->getSlug();
        self::assertMatchesRegularExpression('/^concert-de-printemps-[0-9a-f]{8}$/', $slug);

        $this->client->request('GET', '/s/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Concert de printemps');
        self::assertSelectorTextContains('#timetable', 'DJ Kumo b2b Nadia K');
        self::assertSelectorTextContains('#lieu', '12 rue des Lilas');
        self::assertSelectorExists('meta[property="og:title"][content="Concert de printemps · Jardin partagé des Lilas"]');
        self::assertSelectorExists('meta[property="og:url"][content="http://localhost/s/'.$slug.'"]');
        self::assertSelectorNotExists('meta[property="og:image"]', 'not generated yet');

        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Location sono', 'Ventes buvette', 'Budget', 'kumo@example.com', 'Entrée par la cour', 'Amélie Dupré', 'Guest', 'Léa Fontaine', '600'] as $prive) {
            self::assertStringNotContainsString($prive, $html);
        }
    }

    public function testWhatsAppLinkCarriesAnEncodedMessage(): void
    {
        $slug = $this->soiree()->getSlug();
        $crawler = $this->client->request('GET', '/s/'.$slug);

        $href = (string) $crawler->filter('#partage-whatsapp')->attr('href');
        self::assertStringStartsWith('https://wa.me/?text=', $href);
        $texte = substr($href, \strlen('https://wa.me/?text='));
        self::assertDoesNotMatchRegularExpression('/[\s·]/u', $texte, 'encoded');
        self::assertStringEndsWith(" · Salle des Lilas\nhttp://localhost/s/".$slug, rawurldecode($texte));
        self::assertStringStartsWith('Concert de printemps · ', rawurldecode($texte));
    }

    public function testVisualIsServedOnceGeneratedAsAScriptlessSvg(): void
    {
        $soiree = $this->soiree();
        $this->client->request('GET', '/s/'.$soiree->getSlug().'/visuel-story.svg');
        self::assertResponseStatusCodeSame(404);

        static::getContainer()->get(VisuelsLineUp::class)->generer($soiree);

        $this->client->request('GET', '/s/'.$soiree->getSlug().'/visuel-story.svg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertResponseHeaderSame('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        $this->client->request('GET', '/s/'.$soiree->getSlug());
        self::assertSelectorExists('meta[property="og:image"][content^="http://localhost/s/'.$soiree->getSlug().'/visuel-carre.svg?v="]');
    }

    public function testUnknownSlugGets404(): void
    {
        $this->client->request('GET', '/s/concert-de-printemps');

        self::assertResponseStatusCodeSame(404);
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }
}
