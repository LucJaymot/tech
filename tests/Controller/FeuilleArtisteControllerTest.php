<?php

namespace App\Tests\Controller;

use App\Booking\FeuillesArtistes;
use App\Collective\ActiveCollective;
use App\Entity\Booking;
use App\Repository\BookingRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Relies on AppFixtures: DJ Kumo plays 23:00–01:00 in B2B with Nadia K, after
 * Lila Moon (20:00–21:00), at the Salle des Lilas.
 */
final class FeuilleArtisteControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testSignedLinkShowsTheSheetWithoutAccount(): void
    {
        $this->client->request('GET', $this->lien('DJ Kumo'));

        self::assertResponseIsSuccessful();
        self::assertNull(static::getContainer()->get(TokenStorageInterface::class)->getToken(), 'The sheet is served to an anonymous visitor.');
        self::assertSelectorTextContains('h1', 'Concert de printemps');
        self::assertSelectorTextContains('main', 'Feuille artiste · DJ Kumo');
        self::assertSelectorTextContains('main', '23:00 – 01:00');
        self::assertSelectorTextContains('main', 'En B2B avec Nadia K');
        self::assertSelectorTextContains('main', 'Salle des Lilas');
        self::assertSelectorTextContains('main', 'Entrée par la cour');
        self::assertSelectorTextContains('main', '2 platines CDJ');
        self::assertSelectorTextContains('#timetable', 'Lila Moon');
        self::assertSelectorTextContains('#timetable', 'DJ Kumo b2b Nadia K');
        // No account, no application navigation, no forms: read-only.
        self::assertSelectorNotExists('nav');
        self::assertSelectorNotExists('form');
    }

    public function testSheetHidesMoneyAndStatus(): void
    {
        $this->client->request('GET', $this->lien('DJ Kumo'));
        $text = $this->client->getCrawler()->filter('body')->text();

        foreach (['250', '600', 'GUSO', 'Cession', 'cachet', 'Contacté', 'kumo@example.com'] as $private) {
            self::assertStringNotContainsStringIgnoringCase($private, $text);
        }
    }

    public function testSheetIsKeptOutOfCachesSearchEnginesAndReferrers(): void
    {
        $this->client->request('GET', $this->lien('DJ Kumo'));

        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testUnsignedOrTamperedLinksAreNotFound(): void
    {
        $lien = $this->lien('DJ Kumo');
        $id = $this->booking('DJ Kumo')->getId();

        $this->client->request('GET', '/feuille-artiste/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', preg_replace('/_hash=[^&]+/', '_hash=forged', $lien) ?? '');
        self::assertResponseStatusCodeSame(404);

        // Same signature, another booking.
        $this->client->request('GET', str_replace('/feuille-artiste/'.$id, '/feuille-artiste/'.$this->booking('Nadia K')->getId(), $lien));
        self::assertResponseStatusCodeSame(404);

        // Same signature, a later expiry.
        $this->client->request('GET', preg_replace('/_expiration=\d+/', '_expiration='.(time() + 10 * 365 * 86400), $lien) ?? '');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredLinkIsGone(): void
    {
        $this->client->request('GET', $this->lien('DJ Kumo', new \DateTimeImmutable('-1 minute')));

        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('main', 'Ce lien a expiré');
    }

    public function testSheetIsReadOnly(): void
    {
        $this->client->request('POST', $this->lien('DJ Kumo'));

        self::assertResponseStatusCodeSame(405);
    }

    public function testDefaultLinkLastsThirtyDays(): void
    {
        self::assertSame(1, preg_match('/_expiration=(\d+)/', $this->lien('DJ Kumo'), $matches));
        // 30 calendar days: one more hour when the period crosses the end of summer time.
        self::assertEqualsWithDelta(new \DateTimeImmutable('+30 days')->getTimestamp(), (int) $matches[1], 60);
    }

    private function lien(string $artiste, ?\DateTimeImmutable $expireLe = null): string
    {
        $lien = static::getContainer()->get(FeuillesArtistes::class)->lien($this->booking($artiste), $expireLe);

        // The artist has no account: forget Camille, her active collective and what she loaded.
        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        static::getContainer()->get(ActiveCollective::class)->reset();
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return $lien;
    }

    private function booking(string $artiste): Booking
    {
        $this->actAs('camille@example.com');
        foreach (static::getContainer()->get(BookingRepository::class)->findAll() as $booking) {
            if ($artiste === $booking->getArtiste()->getNom()) {
                return $booking;
            }
        }
        self::fail(\sprintf('%s is booked in the fixtures.', $artiste));
    }
}
