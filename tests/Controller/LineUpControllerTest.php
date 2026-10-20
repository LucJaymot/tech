<?php

namespace App\Tests\Controller;

use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\Soiree;
use App\Enum\ModeRemuneration;
use App\Repository\ArtisteRepository;
use App\Repository\BookingRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relies on AppFixtures: on "Concert de printemps" (Jardin), Lila Moon 20:00–21:00
 * (confirmed), DJ Kumo (declared to the Guso) b2b Nadia K (contacted) 23:00–01:00.
 * Théo is member, Inès reader, Camille admin; Hugo is only in the Atelier.
 */
final class LineUpControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testMemberAddsANewArtistWithEveryDetail(): void
    {
        $soiree = $this->nouvelleSoiree();
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/soirees/'.$soiree->getId().'/line-up');
        $this->client->click($crawler->filter('#ajouter-artiste')->link());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Ajouter au line-up', [
            'programmation[nouvelArtiste][nom]' => 'Kali Rhythm',
            'programmation[nouvelArtiste][email]' => 'kali@example.com',
            'programmation[nouvelArtiste][telephone]' => '06 11 22 33 44',
            'programmation[cachet]' => '450,50',
            'programmation[modeRemuneration]' => 'guso',
            'programmation[debut]' => '23:30',
            'programmation[fin]' => '01:30',
            'programmation[rider][installationMinutes]' => '20',
            'programmation[rider][balanceMinutes]' => '15',
            'programmation[rider][materielDemande]' => "2 CDJ-3000\nTable DJM-900",
            'programmation[rider][materielApporte]' => 'Clé USB',
            'programmation[hospitalite][repas]' => '2',
            'programmation[hospitalite][regimes]' => 'végétarien, sans gluten',
            'programmation[hospitalite][invitations]' => '3',
            'programmation[hospitalite][hebergement]' => '1',
        ]);

        $booking = $this->booking($this->soiree('Nuit techno'), 'Kali Rhythm');
        self::assertResponseRedirects('/soirees/'.$soiree->getId().'/line-up#booking-'.$booking->getId());
        self::assertSame('jardin-des-lilas', $booking->getArtiste()->getCollective()->getSlug(), 'in the collective\'s address book');
        self::assertSame('06 11 22 33 44', $booking->getArtiste()->getTelephone());
        self::assertSame(45050, $booking->getCachet());
        self::assertSame(ModeRemuneration::Guso, $booking->getModeRemuneration());
        self::assertSame('contacte', $booking->getStatut());
        self::assertSame(['2 CDJ-3000', 'Table DJM-900'], $booking->getRider()->materielDemande);
        self::assertSame(20, $booking->getRider()->installationMinutes);
        self::assertSame(['végétarien', 'sans gluten'], $booking->getHospitalite()->regimes);
        self::assertSame(3, $booking->getHospitalite()->invitations);
        self::assertTrue($booking->getHospitalite()->hebergement);
        self::assertSame($soiree->getDate()->modify('+1 day')->format('Y-m-d').' 01:30', $booking->getPassage()?->getFin()->format('Y-m-d H:i'), 'after midnight');
        self::assertTrue($this->soiree($soiree->getNom())->hasArtistesPayes(), 'a paid artist');

        $this->client->followRedirect();
        self::assertSelectorTextContains('#artistes', 'Kali Rhythm');
        self::assertSelectorTextContains('#artistes', '450,50');
        self::assertSelectorTextContains('#timetable', '23:30 – 01:30');
    }

    public function testSameHoursAsAnotherArtistMakeAB2B(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $passages = $concert->getPassages()->count();
        $this->actAs('camille@example.com');
        $artiste = new Artiste($concert->getCollective(), 'Invité surprise');
        $this->entityManager()->persist($artiste);
        $this->entityManager()->flush();

        $this->login('theo@example.com');
        $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up/ajouter');
        $this->client->submitForm('Ajouter au line-up', [
            'programmation[artiste]' => (string) $artiste->getId(),
            'programmation[cachet]' => '0',
            'programmation[modeRemuneration]' => 'cession',
            'programmation[debut]' => '23:00',
            'programmation[fin]' => '01:00',
        ]);

        self::assertResponseRedirects();
        $concert = $this->soiree('Concert de printemps');
        self::assertCount($passages, $concert->getPassages(), 'joined the existing passage');
        self::assertSame(['DJ Kumo', 'Nadia K', 'Invité surprise'], array_values($this->booking($concert, 'Invité surprise')->getPassage()?->getBookings()->map(static fn (Booking $b): string => $b->getArtiste()->getNom())->toArray() ?? []));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function saisiesRefusees(): iterable
    {
        yield 'overlapping another passage' => [['programmation[nouvelArtiste][nom]' => 'Kali', 'programmation[debut]' => '20:30', 'programmation[fin]' => '21:30'], 'chevauche celui de'];
        yield 'new artist without a name' => [['programmation[nouvelArtiste][email]' => 'kali@example.com'], 'Cette valeur ne doit pas être vide.'];
        yield 'no fee' => [['programmation[nouvelArtiste][nom]' => 'Kali', 'programmation[cachet]' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'not an amount' => [['programmation[nouvelArtiste][nom]' => 'Kali', 'programmation[cachet]' => 'beaucoup'], 'Saisissez un montant'];
    }

    /**
     * @param array<string, string> $saisie
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('saisiesRefusees')]
    public function testInvalidInputIsRefused(array $saisie, string $message): void
    {
        $concert = $this->soiree('Concert de printemps');
        $avant = $concert->getBookings()->count();
        $this->login('theo@example.com');
        $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up/ajouter');
        $this->client->submitForm('Ajouter au line-up', $saisie + ['programmation[cachet]' => '100', 'programmation[modeRemuneration]' => 'cession']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', $message);
        self::assertSame($avant, static::getContainer()->get(BookingRepository::class)->count(['soiree' => $concert]));
    }

    public function testAnArtistIsBookedOncePerSoiree(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $this->actAs('camille@example.com');
        $lila = static::getContainer()->get(ArtisteRepository::class)->findOneBy(['nom' => 'Lila Moon']);
        self::assertInstanceOf(Artiste::class, $lila);

        $this->login('theo@example.com');
        $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up/ajouter');
        $this->client->submitForm('Ajouter au line-up', ['programmation[artiste]' => (string) $lila->getId(), 'programmation[cachet]' => '100', 'programmation[modeRemuneration]' => 'cession']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Cet artiste est déjà programmé sur cette soirée.');
    }

    public function testEditingShowsTheCurrentDetailsAndSavesChanges(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $nadia = $this->booking($concert, 'Nadia K');
        $this->login('theo@example.com');

        $crawler = $this->client->request('GET', '/line-up/'.$nadia->getId().'/modifier');
        self::assertSame('23:00', $crawler->filter('#programmation_debut')->attr('value'));
        self::assertSame('250,00', $crawler->filter('#programmation_cachet')->attr('value'));
        self::assertSame('sans gluten', $crawler->filter('#programmation_hospitalite_regimes')->attr('value'));
        self::assertNotNull($crawler->filter('#programmation_artiste')->attr('disabled'), 'the artist stays');

        $this->client->submitForm('Enregistrer', ['programmation[cachet]' => '300', 'programmation[hospitalite][invitations]' => '4']);

        self::assertResponseRedirects();
        $nadia = $this->booking($this->soiree('Concert de printemps'), 'Nadia K');
        self::assertSame(30000, $nadia->getCachet());
        self::assertSame(4, $nadia->getHospitalite()->invitations);
        self::assertSame('23:00', $nadia->getPassage()?->getDebut()->format('H:i'), 'still b2b with DJ Kumo');
    }

    public function testStatusMovesOnThroughTheWorkflow(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $nadia = $this->booking($concert, 'Nadia K');
        $this->login('theo@example.com');

        $crawler = $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up');
        $carte = $crawler->filter('#booking-'.$nadia->getId());
        self::assertSame(['Passer en négociation'], $carte->filter('form[action*="/statut/"] button')->each(static fn ($b): string => $b->text()));
        $this->client->submit($carte->selectButton('Passer en négociation')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#booking-'.$nadia->getId(), 'En négociation');

        // The Guso declaration and the payment are for admins.
        $kumo = $this->booking($concert, 'DJ Kumo');
        self::assertCount(0, $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up')->filter('#booking-'.$kumo->getId().' form[action*="/statut/"]'));
        $this->login('camille@example.com');
        $boutons = $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up')->filter('#booking-'.$kumo->getId().' form[action*="/statut/"] button');
        self::assertSame(['Marquer payé'], $boutons->each(static fn ($b): string => $b->text()));
    }

    public function testOnlyArtistsNotYetConfirmedCanBeRemoved(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $nadia = $this->booking($concert, 'Nadia K');
        $lila = $this->booking($concert, 'Lila Moon');
        $this->login('theo@example.com');

        $crawler = $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up');
        self::assertCount(0, $crawler->filter('#booking-'.$lila->getId().' form[action$="/retirer"]'));
        $this->client->submit($crawler->filter('#booking-'.$nadia->getId())->selectButton('Retirer')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Nadia K retiré du line-up.');
        self::assertSame(['DJ Kumo'], array_values($this->booking($this->soiree('Concert de printemps'), 'DJ Kumo')->getPassage()?->getBookings()->map(static fn (Booking $b): string => $b->getArtiste()->getNom())->toArray() ?? []));
    }

    public function testReaderSeesTheLineUpWithoutContactsNorActions(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $this->login('ines@example.com');

        $crawler = $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#artistes', 'DJ Kumo');
        self::assertCount(0, $crawler->filter('#ajouter-artiste, #artistes form, [data-lien-feuille], a[href^="mailto:"]'));

        $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up/ajouter');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberSeesTheArtistSheetLink(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/soirees/'.$concert->getId().'/line-up');

        self::assertStringContainsString('/feuille-artiste/', (string) $crawler->filter('[data-lien-feuille]')->first()->attr('href'));
    }

    public function testMemberOfAnotherCollectiveGets404(): void
    {
        $concert = $this->soiree('Concert de printemps');
        $nadia = $this->booking($concert, 'Nadia K');
        $this->login('hugo@example.com');

        foreach (['/soirees/'.$concert->getId().'/line-up', '/soirees/'.$concert->getId().'/line-up/ajouter', '/line-up/'.$nadia->getId().'/modifier'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    private function nouvelleSoiree(): Soiree
    {
        $concert = $this->soiree('Concert de printemps');
        $soiree = new Soiree($concert->getCollective(), 'Nuit techno', new \DateTimeImmutable('+30 days'), new \DateTimeImmutable('23:00'), new \DateTimeImmutable('06:00'));
        $this->entityManager()->persist($soiree);
        $this->entityManager()->flush();
        self::assertFalse($soiree->hasArtistesPayes());

        return $soiree;
    }

    private function booking(Soiree $soiree, string $artiste): Booking
    {
        foreach ($soiree->getBookings() as $booking) {
            if ($booking->getArtiste()->getNom() === $artiste) {
                return $booking;
            }
        }
        self::fail(\sprintf('%s is not on %s.', $artiste, $soiree->getNom()));
    }

    private function soiree(string $nom): Soiree
    {
        $this->actAs('camille@example.com');
        $this->entityManager()->clear();
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $this->entityManager()->clear();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
