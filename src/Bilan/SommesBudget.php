<?php

namespace App\Bilan;

use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;

/**
 * Budget sums of one soirée for one line type and category, in cents, from
 * App\Repository\LigneBudgetRepository::sommesDeLAnnee(). Type and category
 * are null for a soirée without lines.
 */
final readonly class SommesBudget
{
    public ?LigneBudgetType $type;

    public ?CategorieBudget $categorie;

    public int $prevu;

    /** Forecast of the lines whose actual amount is known. */
    public int $prevuConnu;

    public int $reel;

    public int $lignes;

    /** Lines whose actual amount is known. */
    public int $connues;

    public function __construct(
        public int $soireeId,
        public string $nom,
        public \DateTimeImmutable $date,
        LigneBudgetType|string|null $type,
        CategorieBudget|string|null $categorie,
        int|string|null $prevu,
        int|string|null $prevuConnu,
        int|string|null $reel,
        int|string $lignes,
        int|string $connues,
    ) {
        $this->type = \is_string($type) ? LigneBudgetType::from($type) : $type;
        $this->categorie = \is_string($categorie) ? CategorieBudget::from($categorie) : $categorie;
        $this->prevu = (int) $prevu;
        $this->prevuConnu = (int) $prevuConnu;
        $this->reel = (int) $reel;
        $this->lignes = (int) $lignes;
        $this->connues = (int) $connues;
    }
}
