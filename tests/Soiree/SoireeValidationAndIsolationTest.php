<?php

namespace App\Tests\Soiree;

use App\Entity\Collective;
use App\Entity\Lieu;
use App\Entity\Soiree;
use App\Repository\CollectiveRepository;
use App\Repository\LieuRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SoireeValidationAndIsolationTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testJaugeCannotExceedTheLieuCapacity(): void
    {
        $this->actAs('camille@example.com');
        $soiree = $this->soiree()->setJauge(121);

        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($soiree);

        self::assertCount(1, $violations);
        self::assertSame('jauge', $violations->get(0)->getPropertyPath());
        self::assertSame('La jauge (121) dépasse la capacité du lieu (120).', $violations->get(0)->getMessage());
    }

    public function testLieuMustBelongToTheSameCollective(): void
    {
        $this->actAs('camille@example.com');
        $soiree = $this->soiree();
        $autre = new Lieu(new Collective('Autre', 'autre'), 'Ailleurs', 'Loin', 500);

        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($soiree->setLieu($autre));

        self::assertCount(1, $violations);
        self::assertSame('lieu', $violations->get(0)->getPropertyPath());
    }

    public function testSoireesAndLieuxOfAnotherCollectiveAreInvisible(): void
    {
        // Hugo creates a lieu and a soirée in the Atelier.
        $this->actAs('hugo@example.com');
        $atelier = static::getContainer()->get(CollectiveRepository::class)->findOneBy(['slug' => 'atelier-velo']);
        self::assertInstanceOf(Collective::class, $atelier);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $lieu = new Lieu($atelier, 'Garage', 'Rue du Vélo', 40);
        $em->persist($lieu);
        $em->persist((new Soiree($atelier, 'Bourse aux vélos', new \DateTimeImmutable('2027-01-10'), new \DateTimeImmutable('10:00'), new \DateTimeImmutable('18:00')))->setLieu($lieu));
        $em->flush();
        $em->clear();

        // Théo only belongs to the Jardin.
        $this->actAs('theo@example.com');
        self::assertSame(['Concert de printemps'], array_map(static fn (Soiree $s): string => $s->getNom(), static::getContainer()->get(SoireeRepository::class)->findAll()));
        self::assertSame(['Salle des Lilas'], array_map(static fn (Lieu $l): string => $l->getNom(), static::getContainer()->get(LieuRepository::class)->findAll()));
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }
}
