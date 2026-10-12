<?php

namespace App\Tests\Controller;

use App\Collective\ActiveCollective;
use App\Entity\Benevole;
use App\Entity\Collective;
use App\Entity\Soiree;
use App\Planning\LiensBenevoles;
use App\Repository\BenevoleRepository;
use App\Repository\CollectiveRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Relies on AppFixtures: Léa is on the Accueil 18:30–22:00 and available for
 * the Buvette 22:00–01:00; Théo and Sam are the other volunteers.
 */
final class EspaceBenevoleControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testVolunteerSeesOwnPlanningWithoutAccount(): void
    {
        $this->client->request('GET', $this->lien('Léa Fontaine'));

        self::assertResponseIsSuccessful();
        self::assertNull(static::getContainer()->get(TokenStorageInterface::class)->getToken(), 'No account involved.');
        self::assertSelectorTextContains('h1', 'Concert de printemps');
        self::assertSelectorTextContains('#planning', '18:30 – 22:00 Accueil');
        self::assertSelectorTextContains('main', 'Salle des Lilas');
        self::assertSame(['Buvette 22:00'], $this->disponibilitesCochees());
        self::assertSelectorCount(3, '#disponibilites input[type="checkbox"]');

        $texte = $this->client->getCrawler()->filter('body')->text();
        foreach (['Théo', 'Sam', 'Camille', 'lea@example.com'] as $autre) {
            self::assertStringNotContainsString($autre, $texte, 'Nothing about the other volunteers.');
        }
        self::assertSelectorNotExists('nav');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    public function testVolunteerUpdatesAvailabilities(): void
    {
        $lien = $this->lien('Léa Fontaine');
        $crawler = $this->client->request('GET', $lien);
        // As the browser sends it with only the 19:00 bar box ticked.
        $this->client->request('POST', $lien, [
            '_token' => $crawler->filter('#disponibilites input[name="_token"]')->attr('value'),
            'creneaux' => [(string) $this->creneauId('Buvette', '19:00')],
        ]);

        self::assertResponseRedirects($lien);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Disponibilités enregistrées, merci !');
        self::assertSame(['Buvette 19:00'], $this->disponibilitesCochees());
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM disponibilite d JOIN benevole b ON b.id = d.benevole_id WHERE b.nom = 'Léa Fontaine'",
        ));
    }

    public function testCreneauxOfAnotherSoireeAreIgnored(): void
    {
        $lien = $this->lien('Sam');
        $crawler = $this->client->request('GET', $lien);
        $token = $crawler->filter('#disponibilites input[name="_token"]')->attr('value');

        $this->client->request('POST', $lien, ['_token' => $token, 'creneaux' => [999999, $this->creneauId('Accueil', '18:30')]]);

        self::assertResponseRedirects($lien);
        $this->client->followRedirect();
        self::assertSame(['Accueil 18:30'], $this->disponibilitesCochees());
    }

    public function testForgedRequestsAreRefused(): void
    {
        $lien = $this->lien('Léa Fontaine');
        $leaId = $this->benevole('Léa Fontaine')->getId();

        $this->client->request('GET', (string) preg_replace('/\?.*$/', '', $lien));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', (string) preg_replace('/_hash=[^&]+/', '_hash=forged', $lien));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', str_replace('/benevole/'.$leaId.'/', '/benevole/'.$this->benevole('Sam')->getId().'/', $lien));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $lien, ['_token' => 'forged', 'creneaux' => []]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testVolunteerOfAnotherCollectiveGetsNothingEvenWithAValidSignature(): void
    {
        $this->actAs('hugo@example.com');
        $atelier = static::getContainer()->get(CollectiveRepository::class)->findOneBy(['slug' => 'atelier-velo']);
        self::assertInstanceOf(Collective::class, $atelier);
        $intrus = new Benevole($atelier, 'Intrus');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($intrus);
        $em->flush();

        $lien = static::getContainer()->get(LiensBenevoles::class)->lien($intrus, $this->soiree());
        $this->anonyme();
        $this->client->request('GET', $lien);

        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredLinkIsGone(): void
    {
        $url = (string) preg_replace('/\?.*$/', '', $this->lien('Léa Fontaine'));
        $this->client->request('GET', static::getContainer()->get(UriSigner::class)->sign($url, new \DateTimeImmutable('-1 minute')));

        self::assertResponseStatusCodeSame(410);
    }

    public function testLinkLastsUntilTwoDaysAfterTheSoiree(): void
    {
        self::assertSame(1, preg_match('/_expiration=(\d+)/', $this->lien('Léa Fontaine'), $m));
        self::assertSame($this->soiree()->getDate()->modify('+3 days')->setTime(0, 0)->getTimestamp(), (int) $m[1]);
    }

    public function testAvailabilitiesAreFrozenOnceTheSoireeIsOver(): void
    {
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE soiree SET statut = 'terminee' WHERE nom = 'Concert de printemps'");
        $lien = $this->lien('Léa Fontaine');
        $crawler = $this->client->request('GET', $lien);

        self::assertSelectorExists('#disponibilites fieldset[disabled]');
        self::assertSelectorNotExists('#disponibilites button');

        $this->client->request('POST', $lien, ['_token' => $crawler->filter('#disponibilites input[name="_token"]')->attr('value'), 'creneaux' => []]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<string>
     */
    private function disponibilitesCochees(): array
    {
        $groupes = $this->client->getCrawler()->filter('#disponibilites fieldset')->each(
            static fn ($fieldset): array => $fieldset->filter('label:has(input:checked)')->each(
                static fn ($label): string => $fieldset->filter('legend')->text().' '.substr(trim($label->text()), 0, 5),
            ),
        );

        return array_merge(...$groupes);
    }

    private function lien(string $benevole): string
    {
        $lien = static::getContainer()->get(LiensBenevoles::class)->lien($this->benevole($benevole), $this->soiree());
        $this->anonyme();

        return $lien;
    }

    private function anonyme(): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        static::getContainer()->get(ActiveCollective::class)->reset();
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function creneauId(string $poste, string $debut): int
    {
        $id = static::getContainer()->get(Connection::class)->fetchOne(
            "SELECT c.id FROM creneau c JOIN poste p ON p.id = c.poste_id WHERE p.intitule = ? AND to_char(c.debut, 'HH24:MI') = ?",
            [$poste, $debut],
        );
        self::assertIsInt($id);

        return $id;
    }

    private function benevole(string $nom): Benevole
    {
        $this->actAs('camille@example.com');
        $benevole = static::getContainer()->get(BenevoleRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Benevole::class, $benevole);

        return $benevole;
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }
}
