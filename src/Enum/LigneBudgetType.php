<?php

namespace App\Enum;

enum LigneBudgetType: string
{
    case Depense = 'depense';
    case Recette = 'recette';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Recette => 'Recette',
        };
    }
}
