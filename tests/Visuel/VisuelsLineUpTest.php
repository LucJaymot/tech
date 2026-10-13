<?php

namespace App\Tests\Visuel;

use App\Entity\Artiste;
use App\Entity\Soiree;
use App\Repository\ArtisteRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use App\Visuel\FormatVisuel;
use App\Visuel\GenererVisuels;
use App\Visuel\IdentiteVisuelle;
use App\Visuel\VisuelsLineUp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Relies on AppFixtures: "Concert de printemps" of the Jardin, Lila Moon
 * then DJ Kumo b2b Nadia K, at the Salle des Lilas.
 */
final class VisuelsLineUpTest extends KernelTestCase
{
    use ActsAsMember;

    public const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
        $storage = static::getContainer()->get('visuels.storage');
        $storage->deleteDirectory('lineups');
        $storage->deleteDirectory('logos');
    }

    public function testRendersBothFormatsAsValidSvg(): void
    {
        foreach ([FormatVisuel::Carre, FormatVisuel::Story] as $format) {
            $svg = $this->visuels()->rendre($this->soiree(), $format);
            $xml = simplexml_load_string($svg);

            self::assertNotFalse($xml, $format->value);
            self::assertSame((string) $format->hauteur(), (string) $xml['height']);
            self::assertSame('1080', (string) $xml['width']);
            self::assertStringContainsString('>Lila Moon</text>', $svg);
            self::assertStringContainsString('>DJ Kumo b2b Nadia K</text>', $svg);
            self::assertMatchesRegularExpression('/>[A-Z][a-zé]+ \d{1,2} [a-zéû]+ \d{4} · 19h00 · Salle des Lilas<\/text>/u', $svg);
            self::assertStringContainsString('fill="#6366f1"', $svg, 'default accent colour');
            self::assertStringContainsString('>CAISSON</text>', $svg);
            self::assertStringContainsString('>Jardin partagé des Lilas</text>', $svg, 'name instead of the missing logo');
        }
    }

    public function testInjectsAccentColourAndLogoAndEscapesNames(): void
    {
        $soiree = $this->soiree();
        $soiree->getCollective()->setCouleurAccent('#FF5500');
        $nadia = static::getContainer()->get(ArtisteRepository::class)->findOneBy(['nom' => 'Nadia K']);
        self::assertInstanceOf(Artiste::class, $nadia);
        $nadia->setNom('Nadia <K> & "co"');
        $this->entityManager()->flush();
        static::getContainer()->get(IdentiteVisuelle::class)->changerLogo($soiree->getCollective(), self::logo());

        $svg = $this->visuels()->rendre($soiree, FormatVisuel::Story);

        self::assertNotFalse(simplexml_load_string($svg));
        self::assertStringContainsString('fill="#ff5500"', $svg);
        self::assertStringContainsString('<image href="data:image/png;base64,'.self::PNG.'"', $svg);
        self::assertStringContainsString('DJ Kumo b2b Nadia &lt;K&gt; &amp; &quot;co&quot;', $svg);
        self::assertStringNotContainsString('>Jardin partagé des Lilas</text>', $svg);
    }

    public function testGenerationGoesThroughMessengerToTheStorage(): void
    {
        $soiree = $this->soiree();
        self::assertNull($this->visuels()->lire($soiree, FormatVisuel::Carre));

        static::getContainer()->get(MessageBusInterface::class)->dispatch(new GenererVisuels((int) $soiree->getId()));
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(GenererVisuels::class, $message);

        $this->visuels()($message);

        foreach (FormatVisuel::cases() as $format) {
            $flux = $this->visuels()->lire($soiree, $format);
            self::assertIsResource($flux);
            self::assertStringContainsString('height="'.$format->hauteur().'"', (string) stream_get_contents($flux));
        }
    }

    public static function logo(): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($chemin, base64_decode(self::PNG, true));

        return new UploadedFile($chemin, 'logo.png', 'image/png', null, true);
    }

    private function visuels(): VisuelsLineUp
    {
        return static::getContainer()->get(VisuelsLineUp::class);
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
