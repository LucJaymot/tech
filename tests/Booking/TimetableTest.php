<?php

namespace App\Tests\Booking;

use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\Collective;
use App\Entity\Passage;
use App\Entity\Soiree;
use App\Enum\ModeRemuneration;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Relies on AppFixtures: "Concert de printemps" has a 20:00–21:00 passage
 * (Lila Moon) and a 23:00–01:00 B2B (DJ Kumo and Nadia K).
 */
final class TimetableTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    public function testFixtureTimetableIsValidWithItsB2B(): void
    {
        $soiree = $this->soiree();

        self::assertCount(0, $this->validate($soiree));
        self::assertSame([false, true], $soiree->getPassages()->map(static fn (Passage $p): bool => $p->estB2B())->getValues());
    }

    public function testOverlappingPassagesAreRefused(): void
    {
        $soiree = $this->soiree();
        new Passage($soiree, $this->at($soiree, 20, 30), $this->at($soiree, 21, 30));

        $violations = $this->validate($soiree);

        self::assertCount(1, $violations);
        self::assertSame('passages', $violations->get(0)->getPropertyPath());
        self::assertStringContainsString('chevauche celui de 20:00 à 21:00', (string) $violations->get(0)->getMessage());
    }

    public function testOverlapAcrossMidnightIsRefused(): void
    {
        $soiree = $this->soiree();
        new Passage($soiree, $this->at($soiree, 0, 30, nextDay: true), $this->at($soiree, 2, 0, nextDay: true));

        self::assertCount(1, $this->validate($soiree));
    }

    public function testBackToBackPassagesAreAllowed(): void
    {
        $soiree = $this->soiree();
        new Passage($soiree, $this->at($soiree, 21, 0), $this->at($soiree, 23, 0));

        self::assertCount(0, $this->validate($soiree));
    }

    public function testPassageMustEndAfterItStarts(): void
    {
        $soiree = $this->soiree();
        new Passage($soiree, $this->at($soiree, 22, 0), $this->at($soiree, 21, 30));

        self::assertSame(['passages[2].fin'], $this->paths($this->validate($soiree)));
    }

    public function testB2BIsOnePassageSharedByBookings(): void
    {
        $soiree = $this->soiree();
        $passage = new Passage($soiree, $this->at($soiree, 21, 0), $this->at($soiree, 22, 0));
        $a = new Booking($soiree, new Artiste($soiree->getCollective(), 'A'), 0, ModeRemuneration::Guso);
        $b = new Booking($soiree, new Artiste($soiree->getCollective(), 'B'), 0, ModeRemuneration::Guso);

        $a->setPassage($passage);
        self::assertFalse($passage->estB2B());
        $b->setPassage($passage);
        self::assertTrue($passage->estB2B());
        self::assertCount(0, $this->validate($soiree));

        $b->setPassage(null);
        self::assertFalse($passage->estB2B());
    }

    public function testBookingCannotUseAnotherSoireesPassageOrArtist(): void
    {
        $soiree = $this->soiree();
        $autre = new Soiree($soiree->getCollective(), 'Autre', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('20:00'), new \DateTimeImmutable('23:00'));
        $passageAilleurs = new Passage($autre, new \DateTimeImmutable('2027-01-01 20:00'), new \DateTimeImmutable('2027-01-01 21:00'));
        $etranger = new Artiste(new Collective('Autre collectif', 'autre'), 'Étranger');

        $booking = (new Booking($soiree, $etranger, 0, ModeRemuneration::Paie))->setPassage($passageAilleurs);

        self::assertSame(['artiste', 'passage'], $this->paths(static::getContainer()->get(ValidatorInterface::class)->validate($booking)));
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function at(Soiree $soiree, int $hour, int $minute, bool $nextDay = false): \DateTimeImmutable
    {
        return $soiree->getDate()->modify($nextDay ? '+1 day' : '+0 day')->setTime($hour, $minute);
    }

    private function validate(Soiree $soiree): ConstraintViolationListInterface
    {
        return static::getContainer()->get(ValidatorInterface::class)->validate($soiree);
    }

    /**
     * @return list<string>
     */
    private function paths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
