<?php

namespace App\Tests\Planning;

use App\Entity\Collective;
use App\Entity\Soiree;
use App\Planning\Horaires;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HorairesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function heures(): iterable
    {
        yield 'evening hour, same day' => ['19:00', '22:00', '2027-03-20 22:00'];
        yield 'after midnight, next day' => ['19:00', '00:30', '2027-03-21 00:30'];
        yield 'h separator' => ['19:00', '1h15', '2027-03-21 01:15'];
        yield 'daytime soirée keeps its morning' => ['10:00', '09:15', '2027-03-20 09:15'];
    }

    #[DataProvider('heures')]
    public function testHourBelongsToTheSoireeNight(string $ouverture, string $heure, string $attendu): void
    {
        self::assertSame($attendu, Horaires::surLaSoiree($this->soiree($ouverture), $heure)->format('Y-m-d H:i'));
    }

    public function testRangeEndingAfterMidnight(): void
    {
        [$debut, $fin] = Horaires::plage($this->soiree('19:00'), '22:00', '01:00');

        self::assertSame(['2027-03-20 22:00', '2027-03-21 01:00'], [$debut->format('Y-m-d H:i'), $fin->format('Y-m-d H:i')]);
    }

    public function testInvalidHourIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Horaires::surLaSoiree($this->soiree('19:00'), '25:00');
    }

    private function soiree(string $ouverture): Soiree
    {
        return new Soiree(new Collective('C', 'c'), 'S', new \DateTimeImmutable('2027-03-20'), new \DateTimeImmutable($ouverture), new \DateTimeImmutable('02:00'));
    }
}
