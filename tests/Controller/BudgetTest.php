<?php

namespace App\Tests\Controller;

use App\Entity\LigneBudget;
use App\Entity\Soiree;
use App\Entity\User;
use App\Repository\LigneBudgetRepository;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Relies on AppFixtures: the budget of "Concert de printemps" (Jardin) has
 * sono 150 €, buvette purchases 250 €, fees 600 € (Lila) and 250 € (Kumo),
 * tickets 800 € and bar sales 450 €, all without actual amounts.
 * Camille is admin, Théo member, Inès reader; Hugo is not in the Jardin.
 */
final class BudgetTest extends WebTestCase
{
    use ActsAsMember;
    use InteractsWithLiveComponents;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $storage = static::getContainer()->get('justificatifs.storage');
        foreach ($storage->listContents('/') as $item) {
            $storage->deleteDirectory($item->path());
        }
        parent::tearDown();
    }

    public function testMemberSeesTotalsEstimatesAndEditableLines(): void
    {
        $this->login('theo@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        // 150 + 250 + 600 + 250 + estimated tax 3,5 % of 800 € = 28 €.
        self::assertSelectorTextSame('#depenses-prevues', "1\u{202F}278,00\u{00A0}€");
        self::assertSelectorTextSame('#recettes-prevues', "1\u{202F}250,00\u{00A0}€");
        self::assertSelectorTextContains('#estimations', 'Taxe sur les spectacles (3,5 %, sur billetterie)');
        self::assertSelectorTextContains('#estimations', 'estimation');
        self::assertSelectorCount(8, 'input[name^="montants["]', 'Only the 4 hand-typed lines are editable.');
        self::assertSelectorCount(6, 'input[name="fichier"]', 'Every line, fee lines included, accepts receipts.');
    }

    public function testReaderSeesTheBudgetReadOnly(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#totaux');
        self::assertSelectorNotExists('input[name^="montants["]');
        self::assertSelectorNotExists('input[name="fichier"]');
        self::assertSelectorNotExists('[data-live-action-param="ajouter"]');
    }

    public function testOtherCollectivesAndAnonymousCannotSeeIt(): void
    {
        $url = $this->url();

        $this->login('hugo@example.com');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseRedirects('/connexion');
    }

    public function testChangingAnAmountSavesItAndRecomputesTheTotals(): void
    {
        $sono = $this->ligne('Location sono');
        $budget = $this->budget('theo@example.com');

        $html = $budget->set("montants.{$sono->getId()}.reel", '160,5')->render()->crawler();

        self::assertSame(16_050, $this->montantReelEnBase($sono));
        self::assertSame("160,50\u{00A0}€", $html->filter('#depenses-reelles')->text());
        self::assertSame('160,50', $html->filter(\sprintf('input[name="montants[%d][reel]"]', $sono->getId()))->attr('value'), 'The amount is shown normalised.');

        $html = $budget->set("montants.{$sono->getId()}.reel", '')->render()->crawler();
        self::assertNull($this->montantReelEnBase($sono), 'An emptied actual amount is unknown again.');
        self::assertSame("0,00\u{00A0}€", $html->filter('#depenses-reelles')->text());
    }

    public function testInvalidAmountIsReportedAndNotSaved(): void
    {
        $sono = $this->ligne('Location sono');

        $html = $this->budget('theo@example.com')->set("montants.{$sono->getId()}.prevu", '12,345')->render()->crawler();

        self::assertStringContainsString('« 12,345 » n\'est pas un montant valide.', $html->filter('#ligne-'.$sono->getId())->text());
        self::assertSame(15_000, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT montant_prevu FROM ligne_budget WHERE id = ?', [$sono->getId()]));
    }

    public function testBreakEvenInEntries(): void
    {
        // Deficit: 1 250 € of expenses − 450 € of bar sales = 800 €; with the 3,5 % tax on tickets: 829,02 €.
        $html = $this->budget('ines@example.com')->set('prixEntree', '12')->render()->crawler();

        self::assertStringContainsString("829,02\u{00A0}€ de billetterie, soit 70 entrées.", $html->filter('#seuil')->text());
    }

    public function testAddAndDeleteALine(): void
    {
        $budget = $this->budget('theo@example.com')
            ->set('nouveauType', 'recette')
            ->set('nouvelleCategorie', 'subventions')
            ->set('nouveauLibelle', 'Aide de la mairie')
            ->set('nouveauPrevu', '500');

        $html = $budget->call('ajouter')->render()->crawler();
        self::assertSame("1\u{202F}750,00\u{00A0}€", $html->filter('#recettes-prevues')->text());

        $mairie = $this->ligne('Aide de la mairie');
        self::assertSame(50_000, $mairie->getMontantPrevu());

        $html = $budget->call('supprimer', ['ligne' => $mairie->getId()])->render()->crawler();
        self::assertSame("1\u{202F}250,00\u{00A0}€", $html->filter('#recettes-prevues')->text());
    }

    public function testInvalidNewLineIsRefused(): void
    {
        $html = $this->budget('theo@example.com')
            ->set('nouveauType', 'depense')
            ->set('nouvelleCategorie', 'billetterie')
            ->set('nouveauLibelle', 'Erreur')
            ->set('nouveauPrevu', '10')
            ->call('ajouter')->render()->crawler();

        self::assertStringContainsString('ne s\'applique pas à ce type de ligne', $html->text());
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM ligne_budget WHERE libelle = 'Erreur'"));
    }

    public function testFeeLinesCannotBeDeleted(): void
    {
        $cachet = $this->ligne('Cachet · Lila Moon');

        $this->budget('camille@example.com')->call('supprimer', ['ligne' => $cachet->getId()]);

        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM ligne_budget WHERE id = ?', [$cachet->getId()]));
    }

    public function testReaderCannotChangeAnything(): void
    {
        $sono = $this->ligne('Location sono');
        $budget = $this->budget('ines@example.com');

        $budget->set("montants.{$sono->getId()}.reel", '999');
        self::assertNull($this->montantReelEnBase($sono));

        $this->expectException(\Throwable::class);
        $budget->call('ajouter');
    }

    public function testReceiptIsStoredAndDownloadedByMembersOnly(): void
    {
        $sono = $this->ligne('Location sono');
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        $pdf = tempnam(sys_get_temp_dir(), 'justif');
        self::assertIsString($pdf);
        file_put_contents($pdf, "%PDF-1.4\nfacture\n");
        $form = $crawler->filter('#ligne-'.$sono->getId().' form')->form();
        $this->joindre($form, $pdf);
        $this->client->submit($form);

        self::assertResponseRedirects($this->url().'#ligne-'.$sono->getId());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Justificatif ajouté.');
        $lien = $crawler->filter('#ligne-'.$sono->getId().' a[href^="/budget/justificatifs/"]');
        self::assertCount(1, $lien);
        $href = (string) $lien->attr('href');

        // A reader of the collective downloads it.
        $this->login('ines@example.com');
        $this->client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame("%PDF-1.4\nfacture\n", $this->client->getInternalResponse()->getContent());

        // Hugo is not in the Jardin.
        $this->login('hugo@example.com');
        $this->client->request('GET', $href);
        self::assertResponseStatusCodeSame(404);
        unlink($pdf);
    }

    public function testReceiptOfADisallowedTypeIsRefused(): void
    {
        $sono = $this->ligne('Location sono');
        $this->login('theo@example.com');
        $crawler = $this->client->request('GET', $this->url());

        $script = tempnam(sys_get_temp_dir(), 'justif');
        self::assertIsString($script);
        file_put_contents($script, "#!/bin/sh\necho hello\n");
        $form = $crawler->filter('#ligne-'.$sono->getId().' form')->form();
        $this->joindre($form, $script);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorExists('[role="alert"]');
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM justificatif'));
        unlink($script);
    }

    public function testCollectivePageLinksToTheBudget(): void
    {
        $this->login('ines@example.com');
        $this->client->request('GET', '/collectif');

        self::assertSelectorExists(\sprintf('#soirees a[href="%s"]', $this->url()));
    }

    private function joindre(Form $form, string $chemin): void
    {
        $champ = $form['fichier'];
        self::assertInstanceOf(FileFormField::class, $champ);
        $champ->upload($chemin);
    }

    private function budget(string $email): TestLiveComponent
    {
        $user = $this->actAs($email);

        return $this->createLiveComponent('Budget', ['soiree' => $this->soiree()], $this->client)->actingAs($user);
    }

    private function login(string $email): User
    {
        $user = $this->actAs($email);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        // A real request starts with an empty identity map.
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        return $user;
    }

    private function url(): string
    {
        return '/soirees/'.$this->soiree()->getId().'/budget';
    }

    private function soiree(): Soiree
    {
        $this->actAs('camille@example.com');
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function ligne(string $libelle): LigneBudget
    {
        $this->actAs('camille@example.com');
        $ligne = static::getContainer()->get(LigneBudgetRepository::class)->findOneBy(['libelle' => $libelle]);
        self::assertInstanceOf(LigneBudget::class, $ligne);

        return $ligne;
    }

    private function montantReelEnBase(LigneBudget $ligne): ?int
    {
        $reel = static::getContainer()->get(Connection::class)->fetchOne('SELECT montant_reel FROM ligne_budget WHERE id = ?', [$ligne->getId()]);

        return null === $reel ? null : (int) $reel;
    }
}
