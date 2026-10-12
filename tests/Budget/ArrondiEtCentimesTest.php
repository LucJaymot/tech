<?php

namespace App\Tests\Budget;

use App\Budget\Arrondi;
use App\Budget\Centimes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrondiEtCentimesTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function divisions(): iterable
    {
        yield 'exact' => [100, 10, 10];
        yield 'below half rounds down' => [4, 10, 0];
        yield 'half rounds up' => [5, 10, 1];
        yield 'one and a half rounds up' => [15, 10, 2];
        yield 'two and a half rounds up' => [25, 10, 3];
        yield 'negative half rounds away from zero' => [-5, 10, -1];
        yield 'zero' => [0, 10_000, 0];
    }

    #[DataProvider('divisions')]
    public function testDiviserRoundsHalfAwayFromZero(int $dividende, int $diviseur, int $attendu): void
    {
        self::assertSame($attendu, Arrondi::diviser($dividende, $diviseur));
    }

    public function testDiviserParExcesRoundsUp(): void
    {
        self::assertSame(0, Arrondi::diviserParExces(0, 1_200));
        self::assertSame(1, Arrondi::diviserParExces(1, 1_200));
        self::assertSame(1, Arrondi::diviserParExces(1_200, 1_200));
        self::assertSame(2, Arrondi::diviserParExces(1_201, 1_200));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function saisiesValides(): iterable
    {
        yield 'integer' => ['12', 1_200];
        yield 'comma, one decimal' => ['12,5', 1_250];
        yield 'dot, two decimals' => ['12.50', 1_250];
        yield 'cents only' => ['0,05', 5];
        yield 'thousands separator and euro sign' => ["1\u{202F}200,00 €", 120_000];
        yield 'surrounding spaces' => ['  7,10 ', 710];
    }

    #[DataProvider('saisiesValides')]
    public function testDepuisSaisie(string $saisie, int $attendu): void
    {
        self::assertSame($attendu, Centimes::depuisSaisie($saisie));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function saisiesInvalides(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'three decimals' => ['12,505'];
        yield 'negative' => ['-3'];
        yield 'two separators' => ['1,2,3'];
        yield 'exponent' => ['12e3'];
    }

    #[DataProvider('saisiesInvalides')]
    public function testDepuisSaisieRefusesInvalidAmounts(string $saisie): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Centimes::depuisSaisie($saisie);
    }

    public function testFormats(): void
    {
        self::assertSame("1\u{202F}234,56\u{00A0}€", Centimes::formater(123_456));
        self::assertSame("0,05\u{00A0}€", Centimes::formater(5));
        self::assertSame("−2,50\u{00A0}€", Centimes::formater(-250));
        self::assertSame('12,50', Centimes::versSaisie(1_250));
        self::assertSame('0,07', Centimes::versSaisie(7));
    }
}
