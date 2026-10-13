<?php

namespace App\Tests\Controller;

use App\Bilan\BilanSoiree;
use App\Budget\Centimes;
use App\Entity\Soiree;
use App\Repository\DebriefingRepository;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relies on AppFixtures: "Concert de printemps" (Jardin) with its budget and
 * its open Guso task. Camille is admin, Théo member, Inès reader; Hugo is
 * only in the Atelier.
 */
final class BilanControllerTest extends WebTestCase
{
    use ActsAsMember;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testReaderSeesTheBilanComputedFromTheBudget(): void
    {
        $attendu = static::getContainer()->get(BilanSoiree::class)->pour($this->soiree())['totaux'];

        $this->login('ines@example.com');
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#bilan tfoot', Centimes::formater($attendu->soldePrevu()));
        self::assertSelectorTextContains('#bilan tbody', 'Taxe sur les spectacles (estimé, 3,5 %)');
        self::assertSelectorTextContains('#bilan tbody', 'Dépense · Technique');
        self::assertCount(0, $crawler->filter('#debriefings form'), 'readers do not write');
    }

    public function testOneDebriefingPerMember(): void
    {
        $this->login('theo@example.com');
        foreach (['Super soirée.', 'Super soirée, son un peu fort.'] as $texte) {
            $form = $this->client->request('GET', $this->url())->filter('#debriefings form')->form();
            $this->client->submit($form, ['texte' => $texte]);
            self::assertResponseRedirects();
        }
        $this->login('camille@example.com');
        $this->client->submit($this->client->request('GET', $this->url())->filter('#debriefings form')->form(), ['texte' => 'Bar à réorganiser.']);
        $crawler = $this->client->followRedirect();

        self::assertSame(2, static::getContainer()->get(DebriefingRepository::class)->count([]));
        self::assertSelectorTextContains('#debriefings', 'Super soirée, son un peu fort.');
        self::assertSelectorTextContains('#debriefings', 'Bar à réorganiser.');
        self::assertSame('Bar à réorganiser.', $crawler->filter('#debriefing-texte')->text(), 'her own text, ready to edit');
    }

    public function testEmptyDebriefingIsRefused(): void
    {
        $this->login('theo@example.com');
        $this->client->submit($this->client->request('GET', $this->url())->filter('#debriefings form')->form(), ['texte' => '   ']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Écrivez quelques mots.');
        self::assertSame(0, static::getContainer()->get(DebriefingRepository::class)->count([]));
    }

    public function testBilanStaysOpenWhileTheGusoDeclarationIsOpen(): void
    {
        $this->terminer();
        $this->login('camille@example.com');
        $crawler = $this->client->request('GET', $this->url());
        self::assertSelectorTextContains('#cloture', 'Démarche encore ouverte : Faire la déclaration unique et simplifiée (Guso)');
        // The POST is refused by the same workflow guard: see SoireeWorkflowTest.
        self::assertCount(0, $crawler->filter('#cloture form'));

        $this->actAs('camille@example.com');
        foreach (static::getContainer()->get(TacheAdminRepository::class)->findApresSoireeOuvertes($this->soiree()) as $tache) {
            $tache->cocher(new \DateTimeImmutable());
        }
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $this->login('camille@example.com');
        $this->client->submit($this->client->request('GET', $this->url())->filter('#cloture form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#cloture', 'Bilan fait');
        self::assertSame('bilan_fait', $this->statut());
    }

    public function testYearlyBilanAndCsvExport(): void
    {
        $soiree = $this->soiree();
        $annee = $soiree->getDate()->format('Y');
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE soiree SET nom = '=HYPERLINK(\"http://x\")' WHERE id = ?", [$soiree->getId()]);
        $totaux = static::getContainer()->get(BilanSoiree::class)->pour($soiree)['totaux'];

        $this->login('ines@example.com');
        $this->client->request('GET', '/bilan/'.$annee);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#bilan-annuel tfoot', Centimes::formater($totaux->soldeReel()));

        $this->client->request('GET', '/bilan/'.$annee.'.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=bilan-jardin-des-lilas-'.$annee.'.csv');
        $lignes = explode("\n", trim($this->client->getInternalResponse()->getContent()));
        self::assertStringStartsWith("\u{FEFF}Soirée;Date;\"Dépenses prévues\"", $lignes[0]);
        self::assertSame(\sprintf('"\'=HYPERLINK(""http://x"")";%s;%s;%s;%s;%s;%s;%s;%d', $soiree->getDate()->format('d/m/Y'), Centimes::versSaisie($totaux->depensesPrevues), Centimes::versSaisie($totaux->depensesReelles), Centimes::versSaisie($totaux->recettesPrevues), Centimes::versSaisie($totaux->recettesReelles), Centimes::versSaisie($totaux->soldePrevu()), Centimes::versSaisie($totaux->soldeReel()), $totaux->lignesSansReel), $lignes[1]);
        self::assertStringStartsWith('Total;;', $lignes[2]);
    }

    public function testYearlyBilanOnlyCoversTheActiveCollective(): void
    {
        $annee = $this->soiree()->getDate()->format('Y');
        $this->login('hugo@example.com');
        $this->client->request('GET', '/bilan/'.$annee);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Concert de printemps');
    }

    private function terminer(): void
    {
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE soiree SET statut = 'terminee' WHERE nom = 'Concert de printemps'");
    }

    private function statut(): string
    {
        return (string) static::getContainer()->get(Connection::class)->fetchOne("SELECT statut FROM soiree WHERE nom = 'Concert de printemps'");
    }

    private function login(string $email): void
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function url(): string
    {
        return '/soirees/'.$this->soiree()->getId().'/bilan';
    }
}
