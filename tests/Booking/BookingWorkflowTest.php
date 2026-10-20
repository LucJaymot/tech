<?php

namespace App\Tests\Booking;

use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\BookingTransition;
use App\Entity\Soiree;
use App\Enum\ModeRemuneration;
use App\Repository\BookingRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Works on bookings created by each test on "Concert de printemps" of the
 * Jardin (AppFixtures), where Camille is admin, Théo member, Inès reader.
 */
final class BookingWorkflowTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testGusoBookingMustBeDeclaredBeforePayment(): void
    {
        $this->actAs('camille@example.com');
        $kumo = $this->nouveauBooking(ModeRemuneration::Guso);

        $this->applyAll($kumo, ['negocier', 'confirmer', 'signer_contrat']);
        self::assertSame(['declarer_guso', 'annuler'], $this->enabled($kumo));

        $this->applyAll($kumo, ['declarer_guso', 'payer']);
        self::assertSame('paye', $kumo->getStatut());
        self::assertSame([], $this->enabled($kumo), 'a paid booking is not cancelled');
    }

    public function testCessionBookingIsPaidWithoutGusoDeclaration(): void
    {
        $this->actAs('camille@example.com');
        $lila = $this->nouveauBooking(ModeRemuneration::Cession);

        $this->applyAll($lila, ['negocier', 'confirmer', 'signer_contrat']);
        self::assertSame(['payer', 'annuler'], $this->enabled($lila));
        self::assertFalse($this->workflow()->can($lila, 'declarer_guso'));

        $this->applyAll($lila, ['payer']);
        self::assertSame('paye', $lila->getStatut());
    }

    public function testMemberNegotiatesButOnlyAdminsHandlePayment(): void
    {
        $this->actAs('theo@example.com');
        $kumo = $this->nouveauBooking(ModeRemuneration::Guso);
        $this->applyAll($kumo, ['negocier', 'confirmer', 'signer_contrat']);

        self::assertSame(['annuler'], $this->enabled($kumo), 'payment is for admins');

        $this->actAs('camille@example.com');
        self::assertSame(['declarer_guso', 'annuler'], $this->enabled($this->recharger($kumo)));
    }

    public function testReaderChangesNothing(): void
    {
        $this->actAs('camille@example.com');
        $booking = $this->nouveauBooking(ModeRemuneration::Paie);

        $this->actAs('ines@example.com');
        self::assertSame([], $this->enabled($this->recharger($booking)));
    }

    public function testHistoryKeepsEveryTransitionWithItsAuthor(): void
    {
        $this->actAs('theo@example.com');
        $kumo = $this->nouveauBooking(ModeRemuneration::Guso);
        $this->applyAll($kumo, ['negocier', 'confirmer', 'signer_contrat']);
        $this->actAs('camille@example.com');
        $this->applyAll($kumo = $this->recharger($kumo), ['declarer_guso', 'payer']);
        $this->em()->clear();

        $history = array_map(
            static fn (BookingTransition $t): string => \sprintf('%s: %s → %s by %s', $t->getTransition(), $t->getDepuis(), $t->getVers(), $t->getEffectueePar()?->getEmail()),
            $this->recharger($kumo)->getTransitions()->toArray(),
        );

        self::assertSame([
            'negocier: contacte → negociation by theo@example.com',
            'confirmer: negociation → confirme by theo@example.com',
            'signer_contrat: confirme → contrat_signe by theo@example.com',
            'declarer_guso: contrat_signe → declare_guso by camille@example.com',
            'payer: declare_guso → paye by camille@example.com',
        ], $history);
    }

    /**
     * @param list<string> $transitions
     */
    private function applyAll(Booking $booking, array $transitions): void
    {
        foreach ($transitions as $transition) {
            $this->workflow()->apply($booking, $transition);
        }
        $this->em()->flush();
    }

    /**
     * @return list<string>
     */
    private function enabled(Booking $booking): array
    {
        return array_values(array_map(static fn ($t): string => $t->getName(), $this->workflow()->getEnabledTransitions($booking)));
    }

    private function nouveauBooking(ModeRemuneration $mode): Booking
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);
        $artiste = new Artiste($soiree->getCollective(), 'Artiste '.$mode->value);
        $booking = new Booking($soiree, $artiste, 30_000, $mode);
        $this->em()->persist($artiste);
        $this->em()->persist($booking);
        $this->em()->flush();

        return $booking;
    }

    private function recharger(Booking $booking): Booking
    {
        $this->em()->clear();
        $recharge = static::getContainer()->get(BookingRepository::class)->find((int) $booking->getId());
        self::assertInstanceOf(Booking::class, $recharge);

        return $recharge;
    }

    private function workflow(): WorkflowInterface
    {
        return static::getContainer()->get('state_machine.booking');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
