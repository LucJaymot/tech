<?php

namespace App\Tests\Budget;

use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\LigneBudget;
use App\Entity\Soiree;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;
use App\Enum\ModeRemuneration;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CachetsBudgetListenerTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    public function testFeeLineFollowsTheBookingFromConfirmationToPayment(): void
    {
        $booking = $this->nouveauBooking(ModeRemuneration::Cession, 42_050);
        self::assertNull($booking->getLigneBudget(), 'No fee line before confirmation.');

        $this->appliquer($booking, ['negocier']);
        self::assertSame([], $this->lignesEnBase($booking));

        $this->appliquer($booking, ['confirmer']);
        self::assertSame([['depense', 'cachets', 'Cachet · Zoé Lune', 42_050, null]], $this->lignesEnBase($booking));
        $ligne = $booking->getLigneBudget();
        self::assertInstanceOf(LigneBudget::class, $ligne);
        self::assertTrue($ligne->estGeneree());
        self::assertTrue($booking->getSoiree()->getLignesBudget()->contains($ligne));

        $booking->setCachet(45_000);
        $this->em()->flush();
        self::assertSame([['depense', 'cachets', 'Cachet · Zoé Lune', 45_000, null]], $this->lignesEnBase($booking));

        $booking->getArtiste()->setNom('Zoé L.');
        $this->em()->flush();
        self::assertSame([['depense', 'cachets', 'Cachet · Zoé L.', 45_000, null]], $this->lignesEnBase($booking), 'Renaming the artist renames the line.');

        $this->appliquer($booking, ['signer_contrat', 'payer']);
        self::assertSame([['depense', 'cachets', 'Cachet · Zoé L.', 45_000, 45_000]], $this->lignesEnBase($booking));
    }

    public function testBookingCreatedAlreadyConfirmedGetsItsLine(): void
    {
        $booking = $this->nouveauBooking(ModeRemuneration::Paie, 10_000, statut: 'confirme');

        self::assertSame([['depense', 'cachets', 'Cachet · Zoé Lune', 10_000, null]], $this->lignesEnBase($booking));
    }

    public function testFeeLineCannotBeChangedByHand(): void
    {
        $booking = $this->nouveauBooking(ModeRemuneration::Guso, 10_000, statut: 'confirme');
        $ligne = $booking->getLigneBudget();
        self::assertInstanceOf(LigneBudget::class, $ligne);

        foreach ([
            static fn () => $ligne->setMontantPrevu(1),
            static fn () => $ligne->setMontantReel(1),
            static fn () => $ligne->setLibelle('x'),
            static fn () => $ligne->setCategorie(CategorieBudget::Divers),
        ] as $modification) {
            try {
                $modification();
                self::fail('A fee line must refuse manual changes.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('cannot be changed by hand', $e->getMessage());
            }
        }
    }

    public function testDeletingTheBookingDeletesItsLine(): void
    {
        $booking = $this->nouveauBooking(ModeRemuneration::Paie, 10_000, statut: 'confirme');
        $id = $booking->getId();
        $this->em()->remove($booking);
        $this->em()->flush();

        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM ligne_budget WHERE booking_id = ?', [$id]));
    }

    public function testCategoryMustMatchTheLineType(): void
    {
        $ligne = new LigneBudget($this->soiree(), LigneBudgetType::Depense, CategorieBudget::Billetterie, 'Erreur', 100);

        $violations = static::getContainer()->get('validator')->validate($ligne);

        self::assertCount(1, $violations);
        self::assertSame('categorie', $violations->get(0)->getPropertyPath());
    }

    private function nouveauBooking(ModeRemuneration $mode, int $cachet, ?string $statut = null): Booking
    {
        $soiree = $this->soiree();
        $artiste = new Artiste($soiree->getCollective(), 'Zoé Lune');
        $booking = new Booking($soiree, $artiste, $cachet, $mode);
        if (null !== $statut) {
            $booking->setStatut($statut);
        }
        $this->em()->persist($artiste);
        $this->em()->persist($booking);
        $this->em()->flush();

        return $booking;
    }

    /**
     * @param list<string> $transitions
     */
    private function appliquer(Booking $booking, array $transitions): void
    {
        $workflow = static::getContainer()->get('state_machine.booking');
        foreach ($transitions as $transition) {
            $workflow->apply($booking, $transition);
        }
        $this->em()->flush();
    }

    /**
     * @return list<array{string, string, string, int, ?int}>
     */
    private function lignesEnBase(Booking $booking): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['type'], (string) $row['categorie'], (string) $row['libelle'], (int) $row['montant_prevu'], null === $row['montant_reel'] ? null : (int) $row['montant_reel']],
            static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT type, categorie, libelle, montant_prevu, montant_reel FROM ligne_budget WHERE booking_id = ?', [$booking->getId()]),
        );
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
