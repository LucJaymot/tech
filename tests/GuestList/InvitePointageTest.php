<?php

namespace App\Tests\GuestList;

use App\Entity\Collective;
use App\Entity\GuestList;
use App\Entity\Invite;
use App\Entity\Soiree;
use App\Entity\User;
use App\Enum\ResultatPointage;
use PHPUnit\Framework\TestCase;

final class InvitePointageTest extends TestCase
{
    private const string A = '11111111-1111-4111-8111-111111111111';
    private const string B = '22222222-2222-4222-8222-222222222222';

    public function testFirstCheckInIsAccepted(): void
    {
        $invite = $this->invite();
        $porte = new User('porte@example.com', 'Porte');

        self::assertSame(ResultatPointage::Accepte, $invite->pointer(self::A, $this->at('21:00'), $porte));
        self::assertSame('21:00', $invite->getPointeAt()?->format('H:i'));
        self::assertSame($porte, $invite->getPointePar());
        self::assertSame(self::A, $invite->getPointageId());
    }

    public function testSendingTheSameCheckInAgainChangesNothing(): void
    {
        $invite = $this->invite();
        $invite->pointer(self::A, $this->at('21:00'), null);

        self::assertSame(ResultatPointage::DejaEnregistre, $invite->pointer(self::A, $this->at('20:00'), null));
        self::assertSame('21:00', $invite->getPointeAt()?->format('H:i'));
    }

    public function testLaterCheckInFromAnotherDeviceLoses(): void
    {
        $invite = $this->invite();
        $invite->pointer(self::A, $this->at('21:00'), null);

        self::assertSame(ResultatPointage::Conflit, $invite->pointer(self::B, $this->at('21:05'), null));
        self::assertSame(ResultatPointage::Conflit, $invite->pointer(self::B, $this->at('21:00'), null), 'At the same second, the saved one stays.');
        self::assertSame(self::A, $invite->getPointageId());
    }

    public function testEarlierCheckInSentLaterWins(): void
    {
        // A device offline since 20:50 sends its check-in after another device's 21:00 one.
        $invite = $this->invite();
        $invite->pointer(self::A, $this->at('21:00'), null);

        self::assertSame(ResultatPointage::Accepte, $invite->pointer(self::B, $this->at('20:50'), null));
        self::assertSame(self::B, $invite->getPointageId());
        self::assertSame('20:50', $invite->getPointeAt()?->format('H:i'));
    }

    public function testCompanionsCountTowardsTheQuota(): void
    {
        $liste = GuestList::pourMembre($this->soiree(), new User('m@example.com', 'M'), 5);
        new Invite($liste, 'A', 2);
        new Invite($liste, 'B');

        self::assertSame(4, $liste->personnes());
        self::assertSame(1, $liste->placesRestantes());
    }

    private function invite(): Invite
    {
        return new Invite(GuestList::pourMembre($this->soiree(), new User('m@example.com', 'M'), 2), 'Hélène');
    }

    private function soiree(): Soiree
    {
        return new Soiree(new Collective('C', 'c'), 'S', new \DateTimeImmutable('2027-03-20'), new \DateTimeImmutable('19:00'), new \DateTimeImmutable('02:00'));
    }

    private function at(string $heure): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-20 '.$heure);
    }
}
