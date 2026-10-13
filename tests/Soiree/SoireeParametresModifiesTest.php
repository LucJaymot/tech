<?php

namespace App\Tests\Soiree;

use App\Entity\Lieu;
use App\Entity\Soiree;
use App\Enum\SoireeParametre;
use App\Event\SoireeParametresModifies;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Relies on AppFixtures: "Concert de printemps" of the Jardin.
 */
final class SoireeParametresModifiesTest extends KernelTestCase
{
    use ActsAsMember;

    /** @var list<SoireeParametresModifies> */
    private array $events = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            SoireeParametresModifies::class,
            function (SoireeParametresModifies $event): void { $this->events[] = $event; },
        );
    }

    /**
     * @return iterable<string, array{\Closure(Soiree, EntityManagerInterface): void, list<SoireeParametre>}>
     */
    public static function changes(): iterable
    {
        yield 'date' => [static function (Soiree $s): void { $s->setDate($s->getDate()->modify('+1 day')); }, [SoireeParametre::Date]];
        yield 'jauge' => [static function (Soiree $s): void { $s->setJauge(80); }, [SoireeParametre::Jauge]];
        yield 'buvette' => [static function (Soiree $s): void { $s->setBuvette(false); }, [SoireeParametre::Buvette]];
        yield 'artistes payés' => [static function (Soiree $s): void { $s->setArtistesPayes(false); }, [SoireeParametre::ArtistesPayes]];
        yield 'lieu' => [static function (Soiree $s, EntityManagerInterface $em): void {
            $lieu = new Lieu($s->getCollective(), 'Parc', 'Allée centrale', 300);
            $em->persist($lieu);
            $s->setLieu($lieu);
        }, [SoireeParametre::Lieu]];
        yield 'jauge and buvette at once' => [static function (Soiree $s): void { $s->setJauge(50)->setBuvette(false); }, [SoireeParametre::Jauge, SoireeParametre::Buvette]];
    }

    /**
     * @param \Closure(Soiree, EntityManagerInterface): void $change
     * @param list<SoireeParametre>                          $expected
     */
    #[DataProvider('changes')]
    public function testChangePublishesOneEvent(\Closure $change, array $expected): void
    {
        $soiree = $this->soiree();
        $change($soiree, $this->entityManager());
        $this->entityManager()->flush();

        self::assertCount(1, $this->events);
        self::assertSame($soiree->getId(), $this->events[0]->soireeId);
        self::assertSame($soiree->getCollective()->getId(), $this->events[0]->collectiveId);
        self::assertSame($expected, $this->events[0]->parametres);
    }

    public function testOtherChangesPublishNothing(): void
    {
        $soiree = $this->soiree();
        $soiree->setNom('Concert d\'été')
            ->setSoutien(true)
            ->setOuverture($soiree->getOuverture()->setTime(20, 0))
            // Same day and same values, new objects: not a change.
            ->setDate(new \DateTimeImmutable($soiree->getDate()->format('Y-m-d')))
            ->setJauge($soiree->getJauge())
            ->setLieu($soiree->getLieu());
        $this->entityManager()->flush();

        self::assertSame([], $this->events);
    }

    public function testNothingIsPublishedBeforeTheChangeIsSaved(): void
    {
        $this->soiree()->setJauge(70);

        self::assertSame([], $this->events);
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
