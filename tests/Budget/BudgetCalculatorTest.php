<?php

namespace App\Tests\Budget;

use App\Budget\BudgetCalculator;
use App\Budget\LigneMontants;
use App\Budget\TauxEstime;
use App\Budget\TauxEstimes;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected values are computed by hand, in cents.
 */
final class BudgetCalculatorTest extends TestCase
{
    private BudgetCalculator $calculator;
    private TauxEstime $taxe;

    protected function setUp(): void
    {
        $this->calculator = new BudgetCalculator();
        $this->taxe = new TauxEstime('taxe_spectacles', 'Taxe sur les spectacles', 350, CategorieBudget::Billetterie);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function taxe(): iterable
    {
        yield '1 cent: 0,035 → 0' => [1, 0];
        yield '14 cents: 0,49 → 0' => [14, 0];
        yield '15 cents: 0,525 → 1' => [15, 1];
        yield '1 €: 3,5 → 4' => [100, 4];
        yield '1,43 €: 5,005 → 5' => [143, 5];
        yield '14,29 €: 50,015 → 50' => [1_429, 50];
        yield '100 €: 350' => [10_000, 350];
    }

    #[DataProvider('taxe')]
    public function testRateIsRoundedHalfUpToTheCent(int $assiette, int $attendu): void
    {
        self::assertSame($attendu, $this->taxe->appliquer($assiette));
    }

    public function testTotalsBalancesAndDifferences(): void
    {
        $totaux = $this->calculator->calculer($this->lignes(), [$this->taxe]);

        // Expenses: 600 + 150 + tax on 800 (28) forecast; 600 + 160,50 + tax on 720 (25,20) actual.
        self::assertSame(77_800, $totaux->depensesPrevues);
        self::assertSame(78_570, $totaux->depensesReelles);
        // Income: 800 + 450 forecast; only the ticket sales are known yet.
        self::assertSame(125_000, $totaux->recettesPrevues);
        self::assertSame(72_000, $totaux->recettesReelles);
        self::assertSame(1, $totaux->lignesSansReel);

        self::assertSame(47_200, $totaux->soldePrevu());
        self::assertSame(-6_570, $totaux->soldeReel());
        // Differences only over known actual amounts: sono +10,50 €, tickets −80 €; bar sales are unknown, not zero.
        self::assertSame(1_050, $totaux->ecartDepenses);
        self::assertSame(-8_000, $totaux->ecartRecettes);

        self::assertCount(1, $totaux->estimations);
        self::assertSame([2_800, 2_520], [$totaux->estimations[0]->prevu, $totaux->estimations[0]->reel]);
    }

    public function testLineDifferenceIsUnknownWithoutActualAmount(): void
    {
        self::assertSame(1_050, new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Technique, 15_000, 16_050)->ecart());
        self::assertSame(0, new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Technique, 15_000, 15_000)->ecart());
        self::assertNull(new LigneMontants(LigneBudgetType::Recette, CategorieBudget::Buvette, 45_000)->ecart());
    }

    public function testBreakEvenAccountsForTheTaxAndItsRounding(): void
    {
        // Deficit to cover: 750 € of expenses − 450 € of other income = 300 €.
        // R − round(3,5 % × R) ≥ 30 000: 31 088 − 1 088 = 30 000, 31 087 − 1 088 = 29 999.
        $totaux = $this->calculator->calculer($this->lignes(), [$this->taxe], prixEntree: 1_200);

        self::assertSame(31_088, $totaux->seuilBilletterie);
        // 310,88 € at 12 € = 25,9 entries → 26.
        self::assertSame(26, $totaux->seuilEntrees);
    }

    public function testBreakEvenWithoutRateIsTheDeficit(): void
    {
        self::assertSame(30_000, $this->calculator->seuilBilletterie($this->lignes(), []));
    }

    public function testNoBreakEvenWhenOtherIncomeCoversExpenses(): void
    {
        $lignes = [
            new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Lieu, 20_000),
            new LigneMontants(LigneBudgetType::Recette, CategorieBudget::Subventions, 20_000),
        ];

        $totaux = $this->calculator->calculer($lignes, [$this->taxe], prixEntree: 1_000);

        self::assertSame(0, $totaux->seuilBilletterie);
        self::assertSame(0, $totaux->seuilEntrees);
    }

    public function testRatesOnOtherCategoriesAreExpensesForTheBreakEven(): void
    {
        $droits = new TauxEstime('droits', 'Droits sur la buvette', 1_000, CategorieBudget::Buvette);
        $lignes = [
            new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Lieu, 10_000),
            new LigneMontants(LigneBudgetType::Recette, CategorieBudget::Buvette, 5_000),
        ];

        // Expenses 100 € + 10 % of 50 € = 105 €; other income 50 € → 55 € to find in tickets, untaxed here.
        self::assertSame(5_500, $this->calculator->seuilBilletterie($lignes, [$droits]));
        self::assertSame(10_500, $this->calculator->calculer($lignes, [$droits])->depensesPrevues);
    }

    public function testRateBaseIsNetOfExpensesInTheSameCategory(): void
    {
        $lignes = [
            new LigneMontants(LigneBudgetType::Recette, CategorieBudget::Buvette, 10_000, 9_000),
            new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Buvette, 4_000, 4_000),
        ];
        $droits = new TauxEstime('droits', 'Droits sur la buvette', 1_000, CategorieBudget::Buvette);

        $estimation = $this->calculator->calculer($lignes, [$droits])->estimations[0];

        self::assertSame([600, 500], [$estimation->prevu, $estimation->reel]);
    }

    public function testEmptyBudget(): void
    {
        $totaux = $this->calculator->calculer([], [$this->taxe], prixEntree: 1_000);

        self::assertSame([0, 0, 0, 0, 0, 0, 0], [$totaux->depensesPrevues, $totaux->depensesReelles, $totaux->recettesPrevues, $totaux->recettesReelles, $totaux->lignesSansReel, $totaux->seuilBilletterie, $totaux->seuilEntrees]);
    }

    public function testRatesComeFromConfiguration(): void
    {
        $taux = new TauxEstimes([['code' => 'taxe_spectacles', 'libelle' => 'Taxe sur les spectacles', 'points_de_base' => 350, 'assiette' => 'billetterie']])->tous();

        self::assertEquals([$this->taxe], $taux);
        self::assertSame('3,5 %', $taux[0]->pourcentage());
        self::assertSame('10 %', new TauxEstime('x', 'x', 1_000, CategorieBudget::Divers)->pourcentage());
        self::assertSame('0,05 %', new TauxEstime('x', 'x', 5, CategorieBudget::Divers)->pourcentage());
    }

    public function testRateMustBeBelowOneHundredPercent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TauxEstime('x', 'x', 10_000, CategorieBudget::Divers);
    }

    /**
     * @return list<LigneMontants>
     */
    private function lignes(): array
    {
        return [
            new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Cachets, 60_000, 60_000),
            new LigneMontants(LigneBudgetType::Depense, CategorieBudget::Technique, 15_000, 16_050),
            new LigneMontants(LigneBudgetType::Recette, CategorieBudget::Billetterie, 80_000, 72_000),
            new LigneMontants(LigneBudgetType::Recette, CategorieBudget::Buvette, 45_000),
        ];
    }
}
