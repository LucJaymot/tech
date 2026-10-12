<?php

namespace App\Tests\Soiree;

use App\Entity\LigneBudget;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Soiree\PiecesJointes;
use App\Soiree\SoireeDuplicator;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Relies on AppFixtures: "Concert de printemps" with 2 postes and 4 budget lines.
 */
final class SoireeDuplicatorTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    protected function tearDown(): void
    {
        $storage = static::getContainer()->get('pieces_jointes.storage');
        foreach ($storage->listContents('/') as $item) {
            $storage->deleteDirectory($item->path());
        }
        parent::tearDown();
    }

    public function testCopiesSettingsPostesAndBudgetButNotTheDate(): void
    {
        $source = $this->soiree();
        $date = new \DateTimeImmutable('2027-03-20');

        $copy = static::getContainer()->get(SoireeDuplicator::class)->duplicate($source, $date);

        self::assertNotNull($copy->getId());
        self::assertNotSame($source->getId(), $copy->getId());
        self::assertSame($source->getCollective(), $copy->getCollective());
        self::assertSame('Concert de printemps', $copy->getNom());
        self::assertSame('2027-03-20', $copy->getDate()->format('Y-m-d'));
        self::assertNotSame($source->getDate()->format('Y-m-d'), $copy->getDate()->format('Y-m-d'));
        self::assertSame('19:00', $copy->getOuverture()->format('H:i'));
        self::assertSame('01:00', $copy->getFermeture()->format('H:i'));
        self::assertSame($source->getLieu(), $copy->getLieu());
        self::assertSame(100, $copy->getJauge());
        self::assertTrue($copy->hasBuvette());
        self::assertTrue($copy->hasArtistesPayes());

        self::assertSame($this->postes($source), $this->postes($copy));
        foreach ($copy->getPostes() as $poste) {
            self::assertSame($copy, $poste->getSoiree());
            self::assertNotNull($poste->getId());
        }
        // Hand-typed lines are copied, forecast only; fee lines follow bookings, which are not copied.
        self::assertSame($this->budget($source, saufCachets: true), $this->budget($copy));
        self::assertCount(4, $copy->getLignesBudget());
        foreach ($copy->getLignesBudget() as $ligne) {
            self::assertFalse($ligne->estGeneree());
            self::assertNull($ligne->getMontantReel());
        }
        self::assertCount(2, $source->getPostes(), 'The source keeps its own postes.');
    }

    public function testCopyStartsInPreparationWithoutAttachments(): void
    {
        $source = $this->soiree();
        static::getContainer()->get('state_machine.soiree')->apply($source, 'confirmer');

        $pdf = tempnam(sys_get_temp_dir(), 'pj');
        self::assertIsString($pdf);
        file_put_contents($pdf, "%PDF-1.4\n%fin\n");
        static::getContainer()->get(PiecesJointes::class)->ajouter($source, new File($pdf), null);

        $copy = static::getContainer()->get(SoireeDuplicator::class)->duplicate($source, new \DateTimeImmutable('2027-03-20'), 'Concert d\'automne');

        self::assertSame('confirmee', $source->getStatut());
        self::assertSame('preparation', $copy->getStatut());
        self::assertSame('Concert d\'automne', $copy->getNom());
        self::assertCount(1, $source->getPiecesJointes());
        self::assertCount(0, $copy->getPiecesJointes());
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    /**
     * @return list<string>
     */
    private function postes(Soiree $soiree): array
    {
        return array_map(
            static fn (Poste $p): string => \sprintf('%s %s-%s x%d', $p->getIntitule(), $p->getDebut()->format('H:i'), $p->getFin()->format('H:i'), $p->getNombrePersonnes()),
            array_values($soiree->getPostes()->toArray()),
        );
    }

    /**
     * @return list<string>
     */
    private function budget(Soiree $soiree, bool $saufCachets = false): array
    {
        return array_values(array_map(
            static fn (LigneBudget $l): string => \sprintf('%s %s %s %d', $l->getType()->value, $l->getCategorie()->value, $l->getLibelle(), $l->getMontantPrevu()),
            array_filter($soiree->getLignesBudget()->toArray(), static fn (LigneBudget $l): bool => !$saufCachets || !$l->estGeneree()),
        ));
    }
}
