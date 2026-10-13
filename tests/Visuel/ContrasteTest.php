<?php

namespace App\Tests\Visuel;

use App\Visuel\Contraste;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContrasteTest extends TestCase
{
    public function testWcagRatio(): void
    {
        self::assertEqualsWithDelta(21.0, Contraste::ratio('#ffffff', '#000000'), 0.001);
        self::assertEqualsWithDelta(1.0, Contraste::ratio('#6366f1', '#6366f1'), 0.001);
        self::assertEqualsWithDelta(4.48, Contraste::ratio('#777777', '#ffffff'), 0.01, 'the classic just-below-AA grey');
    }

    public function testTextOnTheAccentIsBlackOrWhite(): void
    {
        self::assertSame('#000000', Contraste::texteSur('#ffff00'));
        self::assertSame('#ffffff', Contraste::texteSur('#1e3a8a'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accents(): iterable
    {
        yield 'default indigo' => ['#6366f1'];
        yield 'navy, unreadable on dark' => ['#000080'];
        yield 'black' => ['#000000'];
        yield 'already light' => ['#fde047'];
    }

    #[DataProvider('accents')]
    public function testAccentIsMadeReadableOnTheDarkBackground(string $accent): void
    {
        $lisible = Contraste::lisibleSur($accent, '#020617');

        self::assertGreaterThanOrEqual(Contraste::MINIMUM, Contraste::ratio($lisible, '#020617'));
        if (Contraste::ratio($accent, '#020617') >= Contraste::MINIMUM) {
            self::assertSame($accent, $lisible, 'unchanged when already readable');
        }
    }

    public function testDarkensOnALightBackground(): void
    {
        $lisible = Contraste::lisibleSur('#fde047', '#ffffff');

        self::assertGreaterThanOrEqual(Contraste::MINIMUM, Contraste::ratio($lisible, '#ffffff'));
    }
}
