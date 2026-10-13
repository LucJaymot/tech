<?php

namespace App\Tests\Controller;

use App\Entity\Collective;
use App\Entity\Soiree;
use App\Repository\CollectiveRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relies on AppFixtures: Camille is admin of the Jardin.
 */
final class AccentEtFramesTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testAccentColourIsInjectedWithReadableVariants(): void
    {
        $this->login();
        $this->jardin()->setCouleurAccent('#000080');
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $crawler = $this->client->request('GET', '/collectif');

        $style = (string) $crawler->filter('html')->attr('style');
        self::assertStringContainsString('--accent: #000080;', $style);
        self::assertStringContainsString('--accent-texte: #ffffff;', $style);
        self::assertMatchesRegularExpression('/--accent-lisible: #(?!000080)[0-9a-f]{6};/', $style, 'navy is lightened on the dark background');
    }

    public function testDefaultAccentWithoutCollective(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertStringContainsString('--accent: #6366f1;', (string) $crawler->filter('html')->attr('style'));
    }

    public function testScreensAreSplitIntoFramesForLiveUpdates(): void
    {
        $this->login();
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);
        $id = $soiree->getId();

        foreach ([
            '/soirees/'.$id.'/retroplanning' => ['retroplanning-taches-'.$id, 'retroplanning-seuils-'.$id],
            '/soirees/'.$id.'/guest-lists' => ['guest-list-ma-liste-'.$id, 'guest-lists-'.$id],
            '/soirees/'.$id.'/bilan' => ['bilan-debriefings-'.$id, 'bilan-cloture-'.$id],
            '/soirees/'.$id.'/feuille-de-route' => ['feuille-de-route-timetable-'.$id, 'feuille-de-route-equipe-'.$id],
            '/soirees/'.$id.'/planning' => ['planning-trous-'.$id],
        ] as $url => $frames) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            foreach ($frames as $frame) {
                // target="_top": navigation stays page-wide until streams come.
                self::assertCount(1, $crawler->filter(\sprintf('turbo-frame#%s[target="_top"]', $frame)), $frame);
            }
        }
    }

    public function testCollectivesStartOnTheFreePlan(): void
    {
        self::assertSame('gratuit', (new Collective('Nouveau', 'nouveau'))->getPlan());
    }

    private function jardin(): Collective
    {
        $collective = static::getContainer()->get(CollectiveRepository::class)->findOneBy(['slug' => 'jardin-des-lilas']);
        self::assertInstanceOf(Collective::class, $collective);

        return $collective;
    }

    private function login(): void
    {
        $user = $this->actAs('camille@example.com');
        $this->client->loginUser($user);
    }
}
