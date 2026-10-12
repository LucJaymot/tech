<?php

namespace App\Tests\Booking;

use App\Entity\Booking;
use App\Entity\BookingTransition;
use App\Repository\BookingRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Relies on AppFixtures: Lila Moon (cession) and DJ Kumo (GUSO) on "Concert de
 * printemps" of the Jardin, where Camille is admin, Théo member, Inès reader.
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
        $kumo = $this->booking('DJ Kumo');

        $this->applyAll($kumo, ['negocier', 'confirmer', 'signer_contrat']);
        self::assertSame(['declarer_guso'], $this->enabled($kumo));

        $this->applyAll($kumo, ['declarer_guso', 'payer']);
        self::assertSame('paye', $kumo->getStatut());
    }

    public function testCessionBookingIsPaidWithoutGusoDeclaration(): void
    {
        $this->actAs('camille@example.com');
        $lila = $this->booking('Lila Moon');

        $this->applyAll($lila, ['negocier', 'confirmer', 'signer_contrat']);
        self::assertSame(['payer'], $this->enabled($lila));
        self::assertFalse($this->workflow()->can($lila, 'declarer_guso'));

        $this->applyAll($lila, ['payer']);
        self::assertSame('paye', $lila->getStatut());
    }

    public function testMemberNegotiatesButOnlyAdminsHandlePayment(): void
    {
        $this->actAs('theo@example.com');
        $kumo = $this->booking('DJ Kumo');
        $this->applyAll($kumo, ['negocier', 'confirmer', 'signer_contrat']);

        self::assertSame([], $this->enabled($kumo));

        $this->actAs('camille@example.com');
        self::assertSame(['declarer_guso'], $this->enabled($this->booking('DJ Kumo')));
    }

    public function testReaderChangesNothing(): void
    {
        $this->actAs('ines@example.com');

        self::assertSame([], $this->enabled($this->booking('Lila Moon')));
    }

    public function testHistoryKeepsEveryTransitionWithItsAuthor(): void
    {
        $this->actAs('theo@example.com');
        $this->applyAll($this->booking('DJ Kumo'), ['negocier', 'confirmer', 'signer_contrat']);
        $this->actAs('camille@example.com');
        $this->applyAll($this->booking('DJ Kumo'), ['declarer_guso', 'payer']);
        $this->em()->clear();

        $history = array_map(
            static fn (BookingTransition $t): string => \sprintf('%s: %s → %s by %s', $t->getTransition(), $t->getDepuis(), $t->getVers(), $t->getEffectueePar()?->getEmail()),
            $this->booking('DJ Kumo')->getTransitions()->toArray(),
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

    private function booking(string $artiste): Booking
    {
        foreach (static::getContainer()->get(BookingRepository::class)->findAll() as $booking) {
            if ($artiste === $booking->getArtiste()->getNom()) {
                return $booking;
            }
        }
        self::fail(\sprintf('%s is booked in the fixtures.', $artiste));
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
