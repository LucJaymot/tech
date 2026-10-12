<?php

namespace App\Budget;

use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;

/**
 * Every budget computation, in integer cents. Pure: no I/O, no state, the
 * same input always gives the same output.
 *
 * Actual totals only count lines whose actual amount is known, and so do the
 * differences: an unknown amount is not a zero. Estimated charges (configured
 * rates) are added to the expenses on both sides.
 */
final class BudgetCalculator
{
    /**
     * @param list<LigneMontants> $lignes
     * @param list<TauxEstime>    $taux
     * @param int|null            $prixEntree average entry price in cents, to express the break-even point in entries
     */
    public function calculer(array $lignes, array $taux, ?int $prixEntree = null): BudgetTotaux
    {
        $depensesPrevues = $depensesReelles = $recettesPrevues = $recettesReelles = $sansReel = $ecartDepenses = $ecartRecettes = 0;
        foreach ($lignes as $ligne) {
            if (LigneBudgetType::Depense === $ligne->type) {
                $depensesPrevues += $ligne->prevu;
                $depensesReelles += $ligne->reel ?? 0;
                $ecartDepenses += $ligne->ecart() ?? 0;
            } else {
                $recettesPrevues += $ligne->prevu;
                $recettesReelles += $ligne->reel ?? 0;
                $ecartRecettes += $ligne->ecart() ?? 0;
            }
            $sansReel += null === $ligne->reel ? 1 : 0;
        }

        $estimations = [];
        foreach ($taux as $t) {
            $estimations[] = $estimation = new Estimation(
                $t,
                $t->appliquer($this->recettesDe($lignes, $t->assiette, reel: false)),
                $t->appliquer($this->recettesDe($lignes, $t->assiette, reel: true)),
            );
            $depensesPrevues += $estimation->prevu;
            $depensesReelles += $estimation->reel;
        }

        $seuil = $this->seuilBilletterie($lignes, $taux);

        return new BudgetTotaux(
            $depensesPrevues,
            $depensesReelles,
            $recettesPrevues,
            $recettesReelles,
            $estimations,
            $sansReel,
            $ecartDepenses,
            $ecartRecettes,
            $seuil,
            null === $prixEntree ? null : $this->entrees($seuil, $prixEntree),
        );
    }

    /**
     * Smallest ticket revenue R such that R, minus the rates on ticket sales,
     * plus the other forecast income, covers the forecast expenses (including
     * the rates on other categories).
     *
     * @param list<LigneMontants> $lignes
     * @param list<TauxEstime>    $taux
     */
    public function seuilBilletterie(array $lignes, array $taux): int
    {
        $depenses = $autresRecettes = 0;
        foreach ($lignes as $ligne) {
            if (LigneBudgetType::Depense === $ligne->type) {
                $depenses += $ligne->prevu;
            } elseif (CategorieBudget::Billetterie !== $ligne->categorie) {
                $autresRecettes += $ligne->prevu;
            }
        }

        $tauxBilletterie = array_values(array_filter($taux, static fn (TauxEstime $t): bool => CategorieBudget::Billetterie === $t->assiette));
        foreach ($taux as $t) {
            if (CategorieBudget::Billetterie !== $t->assiette) {
                $depenses += $t->appliquer($this->recettesDe($lignes, $t->assiette, reel: false));
            }
        }

        $deficit = $depenses - $autresRecettes;
        if ($deficit <= 0) {
            return 0;
        }

        $netDe = static fn (int $billetterie): int => $billetterie - array_sum(array_map(static fn (TauxEstime $t): int => $t->appliquer($billetterie), $tauxBilletterie));

        // Start from the exact solution, then absorb the rounding of each rate.
        $pointsDeBase = array_sum(array_map(static fn (TauxEstime $t): int => $t->pointsDeBase, $tauxBilletterie));
        $seuil = Arrondi::diviserParExces($deficit * 10_000, 10_000 - min($pointsDeBase, 9_999));
        while ($netDe($seuil) < $deficit) {
            ++$seuil;
        }
        while ($seuil > 0 && $netDe($seuil - 1) >= $deficit) {
            --$seuil;
        }

        return $seuil;
    }

    /**
     * Entries to sell at $prixEntree cents to reach $billetterie cents.
     */
    public function entrees(int $billetterie, int $prixEntree): int
    {
        if ($prixEntree <= 0) {
            throw new \InvalidArgumentException('The entry price must be positive.');
        }

        return Arrondi::diviserParExces(max(0, $billetterie), $prixEntree);
    }

    /**
     * @param list<LigneMontants> $lignes
     */
    private function recettesDe(array $lignes, CategorieBudget $categorie, bool $reel): int
    {
        $total = 0;
        foreach ($lignes as $ligne) {
            if ($ligne->categorie === $categorie) {
                $montant = $reel ? ($ligne->reel ?? 0) : $ligne->prevu;
                $total += LigneBudgetType::Recette === $ligne->type ? $montant : -$montant;
            }
        }

        return max(0, $total);
    }
}
