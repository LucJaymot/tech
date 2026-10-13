<?php

namespace App\Bilan;

use App\Budget\BudgetCalculator;
use App\Budget\BudgetTotaux;
use App\Budget\LigneMontants;
use App\Budget\TauxEstimes;
use App\Entity\LigneBudget;
use App\Entity\Soiree;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;

/**
 * Financial bilan of a soirée, actual against forecast: computed from its
 * budget lines by BudgetCalculator each time, never stored.
 */
final class BilanSoiree
{
    public function __construct(
        private readonly BudgetCalculator $calculator,
        private readonly TauxEstimes $taux,
    ) {
    }

    /**
     * @return array{totaux: BudgetTotaux, categories: list<array{type: LigneBudgetType, categorie: CategorieBudget, lignes: int, totaux: BudgetTotaux}>}
     */
    public function pour(Soiree $soiree): array
    {
        $parCategorie = [];
        foreach ($soiree->getLignesBudget() as $ligne) {
            $parCategorie[$ligne->getType()->value.'.'.$ligne->getCategorie()->value][] = $ligne;
        }
        ksort($parCategorie);

        return [
            'totaux' => $this->calculator->calculer(array_values(array_map(static fn (LigneBudget $l): LigneMontants => $l->montants(), $soiree->getLignesBudget()->toArray())), $this->taux->tous()),
            'categories' => array_values(array_map(fn (array $lignes): array => [
                'type' => $lignes[0]->getType(),
                'categorie' => $lignes[0]->getCategorie(),
                'lignes' => \count($lignes),
                // Without rates: the estimated charges are shown once, on the totals.
                'totaux' => $this->calculator->calculer(array_map(static fn (LigneBudget $l): LigneMontants => $l->montants(), $lignes), []),
            ], $parCategorie)),
        ];
    }
}
