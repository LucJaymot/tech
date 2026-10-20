<?php

namespace App\Tests\Controller;

use App\Entity\Collective;
use App\Entity\Soiree;
use App\Repository\CollectiveRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use App\Tests\Visuel\VisuelsLineUpTest;
use App\Visuel\GenererVisuels;
use App\Visuel\VisuelsLineUp;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Relies on AppFixtures: Camille is admin of the Jardin, Théo member, Inès reader.
 */
final class VisuelsControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $storage = static::getContainer()->get('visuels.storage');
        $storage->deleteDirectory('lineups');
        $storage->deleteDirectory('logos');
    }

    public function testMemberGeneratesTheVisualsAsynchronously(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#visuels figure'));
        self::assertSelectorTextContains('#visuels', 'Pas encore généré');
        self::assertSelectorExists('#partage-whatsapp[href^="https://wa.me/?text="]');

        $this->client->submit($crawler->selectButton('Générer les visuels')->form());
        self::assertResponseRedirects($this->url());

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = array_map(static fn ($e): object => $e->getMessage(), $transport->getSent());
        self::assertCount(1, $messages);
        self::assertInstanceOf(GenererVisuels::class, $messages[0]);
        static::getContainer()->get(VisuelsLineUp::class)($messages[0]);

        $crawler = $this->client->request('GET', $this->url());
        self::assertSame(['Visuel Carré 1080 × 1080', 'Visuel Story 1080 × 1920'], $crawler->filter('#visuels img')->each(static fn ($img): string => (string) $img->attr('alt')));
        $this->client->request('GET', (string) $crawler->filter('#visuels img')->eq(1)->attr('src'));
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    }

    public function testReaderCannotOpenIt(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSetsAccentColourAndLogo(): void
    {
        $this->login('camille@example.com');
        $crawler = $this->client->request('GET', '/collectif');
        $form = $crawler->filter('#identite form')->form();
        $form['couleur'] = '#FF5500';
        $logo = $form['logo'];
        self::assertInstanceOf(FileFormField::class, $logo);
        $logo->upload(VisuelsLineUpTest::logo()->getPathname());
        $this->client->submit($form);
        self::assertResponseRedirects('/collectif#identite');

        $collective = $this->jardin();
        self::assertSame('#ff5500', $collective->getCouleurAccent());
        self::assertStringStartsWith('logos/'.$collective->getId().'/', (string) $collective->getLogo());
    }

    public function testIdentityDressesTheAppAndThePublicPage(): void
    {
        $this->changerIdentite(['couleur' => '#ff5500', 'style_titres' => 'mono'], logo: true);

        $crawler = $this->client->request('GET', '/collectif');
        self::assertStringContainsString('--font-titres: var(--font-mono);', (string) $crawler->filter('html')->attr('style'));
        $logo = (string) $crawler->filter('header img')->attr('src');
        self::assertSame($logo, $crawler->filter('#logo-actuel')->attr('src'));
        self::assertSame('mono', $crawler->filter('#identite select[name="style_titres"] option[selected]')->attr('value'));

        // The visitor of the public page has no collective: it still wears the soirée's.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/s/'.$this->soiree()->getSlug());
        $style = (string) $crawler->filter('html')->attr('style');
        self::assertStringContainsString('--accent: #ff5500;', $style);
        self::assertStringContainsString('--font-titres: var(--font-mono);', $style);
        self::assertSame($logo, $crawler->filter('main img')->first()->attr('src'));

        $this->client->request('GET', $logo);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAdminRemovesTheLogo(): void
    {
        $this->changerIdentite([], logo: true);
        $ancien = (string) $this->client->request('GET', '/collectif')->filter('#logo-actuel')->attr('src');

        $this->changerIdentite(['retirer_logo' => '1']);

        self::assertNull($this->jardin()->getLogo());
        self::assertCount(0, $this->client->request('GET', '/collectif')->filter('#logo-actuel, header img'));
        self::assertSame([], static::getContainer()->get('visuels.storage')->listContents('logos', true)->filter(static fn ($f): bool => $f->isFile())->toArray());
        $this->client->request('GET', $ancien);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownStyleKeepsTheCurrentOne(): void
    {
        $this->changerIdentite(['style_titres' => 'comic-sans']);

        self::assertSame('grotesk', $this->jardin()->getStyleTitres()->value);
    }

    public function testInvalidColourIsRejected(): void
    {
        $this->login('camille@example.com');
        $token = $this->client->request('GET', '/collectif')->filter('#identite input[name="_token"]')->attr('value');
        $this->client->request('POST', '/collectif/identite', ['_token' => $token, 'couleur' => 'red"/><script>']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Choisissez une couleur au format #rrggbb.');
        self::assertSame('#6e8bff', $this->jardin()->getCouleurAccent());
    }

    public function testMembersCannotChangeTheIdentity(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/collectif');
        self::assertCount(0, $crawler->filter('#identite'));
    }

    /**
     * @param array<string, string> $champs
     */
    private function changerIdentite(array $champs, bool $logo = false): void
    {
        $this->login('camille@example.com');
        // Lets a tampered request through, as a script would send it.
        $form = $this->client->request('GET', '/collectif')->filter('#identite form')->form()->disableValidation();
        foreach ($champs as $nom => $valeur) {
            $champ = $form[$nom];
            if ($champ instanceof ChoiceFormField && 'checkbox' === $champ->getType()) {
                $champ->tick();
            } else {
                $form[$nom] = $valeur;
            }
        }
        if ($logo) {
            $fichier = $form['logo'];
            self::assertInstanceOf(FileFormField::class, $fichier);
            $fichier->upload(VisuelsLineUpTest::logo()->getPathname());
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/collectif#identite');
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function jardin(): Collective
    {
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $collective = static::getContainer()->get(CollectiveRepository::class)->findOneBy(['slug' => 'jardin-des-lilas']);
        self::assertInstanceOf(Collective::class, $collective);

        return $collective;
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

        return '/soirees/'.$soiree->getId().'/visuels';
    }
}
