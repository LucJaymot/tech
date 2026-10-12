<?php

namespace App\Budget;

/**
 * Totals of a budget, in cents. Expense totals include the estimated charges.
 */
final readonly class BudgetTotaux
{
    /**
     * @param list<Estimation> $estimations
     */
    public function __construct(
        public int $depensesPrevues,
        public int $depensesReelles,
        public int $recettesPrevues,
        public int $recettesReelles,
        public array $estimations,
        /** Lines whose actual amount is still unknown. */
        public int $lignesSansReel,
        /** Actual minus forecast, over the expense lines whose actual amount is known. */
        public int $ecartDepenses,
        /** Actual minus forecast, over the income lines whose actual amount is known. */
        public int $ecartRecettes,
        /** Ticket sales needed to cover the forecast expenses, after the rates applied to them. */
        public int $seuilBilletterie,
        /** Entries needed at the given average price, null without a price. */
        public ?int $seuilEntrees,
    ) {
    }

    public function soldePrevu(): int
    {
        return $this->recettesPrevues - $this->depensesPrevues;
    }

    public function soldeReel(): int
    {
        return $this->recettesReelles - $this->depensesReelles;
    }
}
