<?php

namespace App\Tests\Soiree;

use App\Entity\Soiree;
use App\Repository\PieceJointeRepository;
use App\Repository\SoireeRepository;
use App\Soiree\PiecesJointes;
use App\Storage\FichierRefuse;
use App\Tests\Support\ActsAsMember;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PiecesJointesTest extends KernelTestCase
{
    use ActsAsMember;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->storage()->listContents('/') as $item) {
            $this->storage()->deleteDirectory($item->path());
        }
        array_map(unlink(...), array_filter($this->tempFiles, is_file(...)));
        parent::tearDown();
    }

    public function testAttachStoresTheFileUnderAGeneratedKey(): void
    {
        $camille = $this->actAs('camille@example.com');
        $soiree = $this->soiree();

        $piece = $this->piecesJointes()->ajouter($soiree, $this->upload("%PDF-1.4\n%fin\n", '../Contrat Artiste.pdf'), $camille);

        self::assertNotNull($piece->getId());
        // The client name is kept for display only, without any path.
        self::assertSame('Contrat Artiste.pdf', $piece->getNomOriginal());
        self::assertSame('application/pdf', $piece->getTypeMime());
        self::assertSame(14, $piece->getTaille());
        self::assertSame($camille, $piece->getAjouteePar());
        self::assertMatchesRegularExpression(\sprintf('#^%d/%d/[0-9a-f]{32}\.pdf$#', $soiree->getCollective()->getId(), $soiree->getId()), $piece->getChemin());
        self::assertTrue($this->storage()->fileExists($piece->getChemin()));

        $stream = $this->piecesJointes()->lire($piece);
        self::assertSame("%PDF-1.4\n%fin\n", stream_get_contents($stream));
    }

    public function testTypeComesFromTheContentNotTheName(): void
    {
        $this->actAs('camille@example.com');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);

        $piece = $this->piecesJointes()->ajouter($this->soiree(), $this->upload($png, 'affiche.pdf'), null);

        self::assertSame('image/png', $piece->getTypeMime());
        self::assertStringEndsWith('.png', $piece->getChemin());
    }

    public function testDisallowedTypeIsRefused(): void
    {
        $this->actAs('camille@example.com');

        $this->expectException(FichierRefuse::class);
        $this->piecesJointes()->ajouter($this->soiree(), $this->upload("#!/bin/sh\necho hello\n", 'script.pdf'), null);
    }

    public function testTooLargeFileIsRefused(): void
    {
        $this->actAs('camille@example.com');
        $content = "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024 + 1);

        try {
            $this->piecesJointes()->ajouter($this->soiree(), $this->upload($content, 'gros.pdf'), null);
            self::fail('A file over 10 MB must be refused.');
        } catch (FichierRefuse $e) {
            self::assertStringContainsString('volumineux', $e->getMessage());
        }
        self::assertSame([], iterator_to_array($this->storage()->listContents('/', true)));
    }

    public function testDeleteRemovesTheFileAndTheRecord(): void
    {
        $this->actAs('camille@example.com');
        $soiree = $this->soiree();
        $piece = $this->piecesJointes()->ajouter($soiree, $this->upload("%PDF-1.4\n", 'a.pdf'), null);
        $id = $piece->getId();

        $this->piecesJointes()->supprimer($piece);

        self::assertFalse($this->storage()->fileExists($piece->getChemin()));
        self::assertNull(static::getContainer()->get(PieceJointeRepository::class)->find((int) $id));
        self::assertCount(0, $soiree->getPiecesJointes());
    }

    private function upload(string $content, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pj');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $clientName, test: true);
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function piecesJointes(): PiecesJointes
    {
        return static::getContainer()->get(PiecesJointes::class);
    }

    private function storage(): FilesystemOperator
    {
        return static::getContainer()->get('pieces_jointes.storage');
    }
}
