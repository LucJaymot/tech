<?php

namespace App\Tests\Controller;

use App\Entity\Lieu;
use App\Entity\Soiree;
use App\Enum\SoireeParametre;
use App\Event\SoireeParametresModifies;
use App\Obligations\RetroplanningGenerator;
use App\Repository\CollectiveRepository;
use App\Repository\LieuRepository;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Relies on AppFixtures: the Jardin with its Salle des Lilas (120 places) and
 * "Concert de printemps"; Théo is member, Inès reader; Hugo is only in the
 * Atelier, where Camille is reader.
 */
final class SoireeFormulaireTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    /** @var list<SoireeParametresModifies> */
    private array $evenements = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            SoireeParametresModifies::class,
            function (SoireeParametresModifies $e): void { $this->evenements[] = $e; },
        );
    }

    public function testMemberCreatesASoireeThatCrossesMidnight(): void
    {
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/soirees');
        $this->client->click($crawler->filter('#nouvelle-soiree')->link());
        self::assertResponseIsSuccessful();

        $ouverture = new \DateTimeImmutable('+40 days 23:00');
        $this->client->submitForm('Créer la soirée', $this->champs([
            'soiree[ouverture]' => $ouverture->format('Y-m-d\TH:i'),
            'soiree[fermeture]' => $ouverture->modify('+7 hours')->format('Y-m-d\TH:i'),
            'soiree[lieu]' => (string) $this->salleDesLilas()->getId(),
            'soiree[buvette]' => '1',
        ]));

        $soiree = $this->soiree('Warehouse #4');
        self::assertResponseRedirects('/soirees/'.$soiree->getId());
        self::assertSame('jardin-des-lilas', $soiree->getCollective()->getSlug(), 'the current collective');
        self::assertSame('preparation', $soiree->getStatut());
        self::assertSame($ouverture->format('Y-m-d 23:00'), $soiree->getOuverture()->format('Y-m-d H:i'));
        self::assertSame($ouverture->modify('+1 day')->format('Y-m-d 06:00'), $soiree->getFermeture()->format('Y-m-d H:i'));
        self::assertSame($ouverture->format('Y-m-d'), $soiree->getDate()->format('Y-m-d'));
        self::assertTrue($soiree->hasBuvette());

        self::assertCount(1, $this->evenements());
        self::assertSame($soiree->getId(), $this->evenements()[0]->soireeId);
        self::assertSame(SoireeParametre::cases(), $this->evenements()[0]->parametres);

        // The rétroplanning generator handles it from Messenger.
        static::getContainer()->get(RetroplanningGenerator::class)($this->messageEnvoye());
        self::assertContains('buvette_temporaire', array_map(static fn ($t): string => $t->getCode(), static::getContainer()->get(TacheAdminRepository::class)->findPourSoiree($soiree)));

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Soirée créée.');
        self::assertSelectorTextContains('#en-tete', '23:00 → 06:00 (lendemain)');
    }

    public function testNewVenueIsCreatedInTheSameForm(): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', '/soirees/nouvelle');
        $this->client->submitForm('Créer la soirée', $this->champs([
            'soiree[nouveauLieu][nom]' => 'Hangar 9',
            'soiree[nouveauLieu][adresse]' => 'Quai des Docks',
            'soiree[nouveauLieu][capacite]' => '800',
            'soiree[jauge]' => '900',
        ]));

        $soiree = $this->soiree('Warehouse #4');
        self::assertResponseRedirects('/soirees/'.$soiree->getId());
        self::assertSame('Hangar 9', $soiree->getLieu()?->getNom());
        self::assertSame('jardin-des-lilas', $soiree->getLieu()->getCollective()->getSlug());
        self::assertFalse($soiree->getLieu()->isAmenagePourPublic());

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'La jauge (900) dépasse la capacité du lieu (800).', 'a warning, the soirée is saved');
    }

    public function testNewVenueNeedsAnAddressAndACapacity(): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', '/soirees/nouvelle');
        $crawler = $this->client->submitForm('Créer la soirée', $this->champs(['soiree[nouveauLieu][nom]' => 'Hangar 9']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('aria-invalid="true"', (string) $crawler->filter('#soiree_nouveauLieu_adresse')->outerHtml());
        self::assertStringContainsString('aria-invalid="true"', (string) $crawler->filter('#soiree_nouveauLieu_capacite')->outerHtml());
        self::assertSame(0, static::getContainer()->get(SoireeRepository::class)->count(['nom' => 'Warehouse #4']));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function saisiesRefusees(): iterable
    {
        $ouverture = new \DateTimeImmutable('+40 days 23:00');
        yield 'closing before opening' => [['soiree[fermeture]' => $ouverture->modify('-1 hour')->format('Y-m-d\TH:i')], 'La fermeture doit être après l\'ouverture.'];
        yield 'longer than 24 hours' => [['soiree[fermeture]' => $ouverture->modify('+25 hours')->format('Y-m-d\TH:i')], 'Une soirée dure 24 heures au plus.'];
        yield 'in the past' => [['soiree[ouverture]' => '2020-01-10T23:00', 'soiree[fermeture]' => '2020-01-11T05:00'], 'La soirée doit avoir lieu dans le futur.'];
        yield 'jauge not an integer' => [['soiree[jauge]' => '12.5'], 'La jauge doit être un nombre entier.'];
        yield 'no name' => [['soiree[nom]' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'no opening' => [['soiree[ouverture]' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'no closing' => [['soiree[fermeture]' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'jauge not positive' => [['soiree[jauge]' => '0'], 'Cette valeur doit être strictement positive.'];
    }

    /**
     * @param array<string, string> $saisie
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('saisiesRefusees')]
    public function testInvalidInputIsRefused(array $saisie, string $message): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', '/soirees/nouvelle');
        $this->client->submitForm('Créer la soirée', $this->champs($saisie));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', $message);
        self::assertSame(0, static::getContainer()->get(SoireeRepository::class)->count(['nom' => 'Warehouse #4']));
        self::assertSame([], $this->evenements());
    }

    public function testVenuesOfAnotherCollectiveCannotBePicked(): void
    {
        $this->actAs('camille@example.com', 'atelier-velo');
        $atelier = static::getContainer()->get(CollectiveRepository::class)->findOneBy(['slug' => 'atelier-velo']);
        self::assertNotNull($atelier);
        $autre = new Lieu($atelier, 'Garage', 'Rue du Vélo', 50);
        static::getContainer()->get('doctrine.orm.entity_manager')->persist($autre);
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', '/soirees/nouvelle');
        $form = $crawler->selectButton('Créer la soirée')->form($this->champs([]));
        $lieu = $form['soiree[lieu]'];
        self::assertInstanceOf(ChoiceFormField::class, $lieu);
        $lieu->disableValidation()->setValue((string) $autre->getId());
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, static::getContainer()->get(SoireeRepository::class)->count(['nom' => 'Warehouse #4']));
    }

    public function testEditingTheNameAlonePublishesNothing(): void
    {
        $url = $this->modifierUrl();
        $this->login('theo@example.com');

        $this->client->request('GET', $url);
        $this->client->submitForm('Enregistrer', ['soiree[nom]' => 'Concert d\'automne']);

        self::assertResponseRedirects(substr($url, 0, -\strlen('/modifier')));
        self::assertSame('Concert d\'automne', $this->soiree('Concert d\'automne')->getNom());
        self::assertSame([], $this->evenements());
    }

    public function testEditingATriggerPublishesTheEvent(): void
    {
        $url = $this->modifierUrl();
        $this->login('theo@example.com');

        $this->client->request('GET', $url);
        $this->client->submitForm('Enregistrer', ['soiree[jauge]' => '80']);

        self::assertResponseRedirects(substr($url, 0, -\strlen('/modifier')));
        self::assertCount(1, $this->evenements());
        self::assertSame([SoireeParametre::Jauge], $this->evenements()[0]->parametres);
    }

    public function testReaderSeesThePageButCannotCreateNorEdit(): void
    {
        $url = $this->modifierUrl();
        $this->login('ines@example.com');

        $crawler = $this->client->request('GET', substr($url, 0, -\strlen('/modifier')));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#modifier');
        $crawler = $this->client->request('GET', '/soirees');
        self::assertCount(0, $crawler->filter('#nouvelle-soiree'));

        foreach (['GET' => '/soirees/nouvelle', 'POST' => '/soirees/nouvelle'] + ['GET ' => $url, 'POST ' => $url] as $methode => $cible) {
            $this->client->request(trim($methode), $cible);
            self::assertResponseStatusCodeSame(403, $methode.$cible);
        }
    }

    public function testMemberOfAnotherCollectiveGets404OnTheForm(): void
    {
        $url = $this->modifierUrl();
        $this->login('hugo@example.com');

        foreach (['GET', 'POST'] as $methode) {
            $this->client->request($methode, $url);
            self::assertResponseStatusCodeSame(404, $methode);
        }
    }

    public function testMemberSeesTheEditButton(): void
    {
        $url = $this->modifierUrl();
        $this->login('theo@example.com');
        $this->client->request('GET', substr($url, 0, -\strlen('/modifier')));

        self::assertSelectorExists('#modifier[href="'.$url.'"]');
    }

    /**
     * @return list<SoireeParametresModifies>
     */
    private function evenements(): array
    {
        return $this->evenements;
    }

    private function messageEnvoye(): SoireeParametresModifies
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SoireeParametresModifies) {
                return $envelope->getMessage();
            }
        }
        self::fail('No SoireeParametresModifies sent to Messenger.');
    }

    /**
     * @param array<string, string> $saisie
     *
     * @return array<string, string>
     */
    private function champs(array $saisie): array
    {
        $ouverture = new \DateTimeImmutable('+40 days 23:00');

        return $saisie + [
            'soiree[nom]' => 'Warehouse #4',
            'soiree[ouverture]' => $ouverture->format('Y-m-d\TH:i'),
            'soiree[fermeture]' => $ouverture->modify('+6 hours')->format('Y-m-d\TH:i'),
        ];
    }

    private function salleDesLilas(): Lieu
    {
        $lieu = static::getContainer()->get(LieuRepository::class)->findOneBy(['nom' => 'Salle des Lilas']);
        self::assertInstanceOf(Lieu::class, $lieu);

        return $lieu;
    }

    private function soiree(string $nom): Soiree
    {
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function modifierUrl(): string
    {
        $this->actAs('camille@example.com');

        return '/soirees/'.$this->soiree('Concert de printemps')->getId().'/modifier';
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }
}
