<?php

namespace App\Tests\Booking;

use App\Booking\Hospitalite;
use App\Booking\Rider;
use App\Entity\Booking;
use App\Enum\ModeRemuneration;
use App\Repository\BookingRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Relies on AppFixtures: Lila Moon booked on "Concert de printemps".
 */
final class RiderHospitaliteStorageTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function paiementsInterdits(): iterable
    {
        foreach (['especes', 'espèces', 'cash', 'liquide', 'auto_entrepreneur', 'auto-entrepreneur', 'facture', 'micro_entreprise'] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('paiementsInterdits')]
    public function testUndeclaredPaymentMethodsDoNotExist(string $value): void
    {
        self::assertNull(ModeRemuneration::tryFrom($value));
    }

    public function testRiderAndHospitaliteRoundTripThroughJsonb(): void
    {
        $booking = $this->lila();
        $booking->setRider($booking->getRider()->withMaterielDemande(['Piano à queue'])->withTemps(45, 30))
            ->setHospitalite(new Hospitalite(3, ['vegan'], 6, true, 'Arrivée en train à 16 h'));
        $this->em()->flush();
        $id = $booking->getId();
        $this->em()->clear();

        $reloaded = $this->em()->find(Booking::class, $id);
        self::assertInstanceOf(Booking::class, $reloaded);
        self::assertEquals(new Rider(45, 30, ['Piano à queue'], ['Guitare'], 'Retour de scène côté jardin.'), $reloaded->getRider());
        self::assertEquals(new Hospitalite(3, ['vegan'], 6, true, 'Arrivée en train à 16 h'), $reloaded->getHospitalite());

        $row = static::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT pg_typeof(rider)::text AS type, rider->>\'balanceMinutes\' AS balance, hospitalite->\'regimes\'->>0 AS regime FROM booking WHERE id = ?',
            [$id],
        );
        self::assertSame(['type' => 'jsonb', 'balance' => '30', 'regime' => 'vegan'], $row);
    }

    public function testMalformedJsonInTheDatabaseIsRejected(): void
    {
        $id = $this->lila()->getId();
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE booking SET rider = \'{"installationMinutes": "trente"}\'::jsonb WHERE id = ?',
            [$id],
        );
        $this->em()->clear();

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('"installationMinutes" must be an integer');
        $this->em()->find(Booking::class, $id);
    }

    public function testDtosAreValidated(): void
    {
        $booking = $this->lila()->setRider(new Rider(-5))->setHospitalite(new Hospitalite(regimes: ['']));

        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($booking);

        self::assertSame(['rider.installationMinutes', 'hospitalite.regimes[0]'], array_map(
            static fn ($v): string => $v->getPropertyPath(),
            iterator_to_array($violations),
        ));
    }

    private function lila(): Booking
    {
        foreach (static::getContainer()->get(BookingRepository::class)->findAll() as $booking) {
            if ('Lila Moon' === $booking->getArtiste()->getNom()) {
                return $booking;
            }
        }
        self::fail('Lila Moon is booked in the fixtures.');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
