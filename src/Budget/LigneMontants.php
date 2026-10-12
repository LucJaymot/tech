<?php

namespace App\Budget;

use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;

/**
 * The amounts of one budget line, as BudgetCalculator needs them.
 */
final readonly class LigneMontants
{
    /**
     * @param int      $prevu in cents
     * @param int|null $reel  in cents, null while unknown
     */
    public function __construct(
        public LigneBudgetType $type,
        public CategorieBudget $categorie,
        public int $prevu,
        public ?int $reel = null,
    ) {
    }

    /**
     * Actual minus forecast, null while the actual amount is unknown.
     */
    public function ecart(): ?int
    {
        return null === $this->reel ? null : $this->reel - $this->prevu;
    }
}
