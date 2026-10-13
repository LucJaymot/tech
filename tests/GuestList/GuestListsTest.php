<?php

namespace App\Tests\GuestList;

use App\Entity\Booking;
use App\Entity\GuestList;
use App\Entity\Soiree;
use App\GuestList\GuestListRefusee;
use App\GuestList\GuestLists;
use App\Repository\BookingRepository;
use App\Repository\GuestListRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Relies on AppFixtures: Lila Moon's list (quota 4) has Amélie +1 and Zoé;
 * DJ Kumo's (quota 2) has Hélène +1; Nadia K has none yet (2 invitations).
 */
final class GuestListsTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    public function testQuotaIsCheckedCompanionsIncluded(): void
    {
        $lila = $this->liste('Lila Moon');

        $this->guestLists()->ajouter($lila, 'Marc', 0);
        self::assertSame(0, $lila->placesRestantes());

        try {
            $this->guestLists()->ajouter($lila, 'Un de trop', 0);
            self::fail('The quota must be enforced.');
        } catch (GuestListRefusee $e) {
            self::assertSame('Plus assez de places : 0 restante sur 4.', $e->getMessage());
        }
        self::assertCount(3, $this->recharger($lila)->getInvites());
    }

    public function testCompanionsCanExceedTheQuota(): void
    {
        $this->expectExceptionObject(new GuestListRefusee('Plus assez de places : 1 restante sur 4.'));
        $this->guestLists()->ajouter($this->liste('Lila Moon'), 'Famille', 1);
        $this->guestLists()->ajouter($this->liste('Lila Moon'), 'Autre famille', 1);
    }

    public function testQuotaIsReadFromTheDatabaseNotFromAStaleObject(): void
    {
        $lila = $this->liste('Lila Moon');
        // Another request lowered the quota meanwhile.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE guest_list SET quota = 3 WHERE id = ?', [$lila->getId()]);

        $this->expectException(GuestListRefusee::class);
        $this->guestLists()->ajouter($lila, 'Marc', 0);
    }

    public function testInvalidGuestsAreRefused(): void
    {
        foreach ([['', 0, 'Indiquez le nom de l\'invité.'], ['Trop', 11, 'Cette valeur doit être comprise entre 0 et 10.']] as [$nom, $accompagnants, $message]) {
            try {
                $this->guestLists()->ajouter($this->liste('Lila Moon'), $nom, $accompagnants);
                self::fail('Invalid guest accepted.');
            } catch (GuestListRefusee $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testArtistListStartsFromTheHospitalityInvitations(): void
    {
        $nadia = $this->booking('Nadia K');
        self::assertNull(static::getContainer()->get(GuestListRepository::class)->findOneBy(['booking' => $nadia]));

        $liste = $this->guestLists()->pourBooking($nadia);

        self::assertSame(2, $liste->getQuota());
        self::assertSame($liste, $this->guestLists()->pourBooking($nadia), 'One list per booking.');
    }

    public function testMemberListUsesTheConfiguredQuota(): void
    {
        $theo = $this->actAs('theo@example.com');
        $liste = $this->guestLists()->pourMembre($this->soiree(), $theo);

        self::assertSame(2, $liste->getQuota());
        self::assertSame('Théo Lambert', $liste->getProprietaire());
    }

    public function testCheckedInGuestCannotBeRemoved(): void
    {
        $invite = $this->liste('DJ Kumo')->getInvites()->first();
        self::assertNotFalse($invite);
        $invite->pointer('33333333-3333-4333-8333-333333333333', new \DateTimeImmutable(), null);

        $this->expectExceptionObject(new GuestListRefusee('Cet invité est déjà arrivé.'));
        $this->guestLists()->retirer($invite);
    }

    private function liste(string $artiste): GuestList
    {
        $liste = static::getContainer()->get(GuestListRepository::class)->findOneBy(['booking' => $this->booking($artiste)]);
        self::assertInstanceOf(GuestList::class, $liste);

        return $liste;
    }

    private function recharger(GuestList $liste): GuestList
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $recharge = static::getContainer()->get(GuestListRepository::class)->find((int) $liste->getId());
        self::assertInstanceOf(GuestList::class, $recharge);

        return $recharge;
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

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function guestLists(): GuestLists
    {
        return static::getContainer()->get(GuestLists::class);
    }
}
