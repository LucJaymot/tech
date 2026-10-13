<?php

namespace App\Bilan;

use App\Budget\BudgetCalculator;
use App\Budget\BudgetTotaux;
use App\Budget\LigneMontants;
use App\Budget\TauxEstimes;
use App\Entity\Collective;
use App\Repository\LigneBudgetRepository;

/**
 * The year of a collective, soirée by soirée, from one aggregated query.
 * Each soirée goes through BudgetCalculator, so its totals match its own
 * budget page, estimated charges included.
 */
final class BilanAnnuel
{
    public function __construct(
        private readonly LigneBudgetRepository $lignes,
        private readonly BudgetCalculator $calculator,
        private readonly TauxEstimes $taux,
    ) {
    }

    /**
     * @return array{soirees: list<array{id: int, nom: string, date: \DateTimeImmutable, totaux: BudgetTotaux, sans_reel: int}>, total: array{depenses_prevues: int, depenses_reelles: int, recettes_prevues: int, recettes_reelles: int, solde_prevu: int, solde_reel: int, sans_reel: int}}
     */
    public function pour(Collective $collective, int $annee): array
    {
        $parSoiree = [];
        foreach ($this->lignes->sommesDeLAnnee($collective, $annee) as $sommes) {
            $parSoiree[$sommes->soireeId] ??= ['id' => $sommes->soireeId, 'nom' => $sommes->nom, 'date' => $sommes->date, 'montants' => [], 'sans_reel' => 0];
            if (null === $sommes->type || null === $sommes->categorie) {
                continue; // soirée without budget lines
            }

            // The sums of a category split in two, known and unknown actual
            // amounts, give BudgetCalculator the same totals as line by line.
            if ($sommes->connues > 0) {
                $parSoiree[$sommes->soireeId]['montants'][] = new LigneMontants($sommes->type, $sommes->categorie, $sommes->prevuConnu, $sommes->reel);
            }
            if ($sommes->lignes > $sommes->connues) {
                $parSoiree[$sommes->soireeId]['montants'][] = new LigneMontants($sommes->type, $sommes->categorie, $sommes->prevu - $sommes->prevuConnu);
            }
            $parSoiree[$sommes->soireeId]['sans_reel'] += $sommes->lignes - $sommes->connues;
        }

        $total = ['depenses_prevues' => 0, 'depenses_reelles' => 0, 'recettes_prevues' => 0, 'recettes_reelles' => 0, 'solde_prevu' => 0, 'solde_reel' => 0, 'sans_reel' => 0];
        $soirees = [];
        foreach ($parSoiree as $soiree) {
            $totaux = $this->calculator->calculer($soiree['montants'], $this->taux->tous());
            $soirees[] = ['id' => $soiree['id'], 'nom' => $soiree['nom'], 'date' => $soiree['date'], 'totaux' => $totaux, 'sans_reel' => $soiree['sans_reel']];
            $total['depenses_prevues'] += $totaux->depensesPrevues;
            $total['depenses_reelles'] += $totaux->depensesReelles;
            $total['recettes_prevues'] += $totaux->recettesPrevues;
            $total['recettes_reelles'] += $totaux->recettesReelles;
            $total['solde_prevu'] += $totaux->soldePrevu();
            $total['solde_reel'] += $totaux->soldeReel();
            $total['sans_reel'] += $soiree['sans_reel'];
        }

        return ['soirees' => $soirees, 'total' => $total];
    }
}
