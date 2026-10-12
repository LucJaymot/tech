<?php

namespace App\Enum;

enum CategorieBudget: string
{
    case Cachets = 'cachets';
    case Technique = 'technique';
    case Lieu = 'lieu';
    case Communication = 'communication';
    case Accueil = 'accueil';
    case Buvette = 'buvette';
    case Billetterie = 'billetterie';
    case Subventions = 'subventions';
    case Divers = 'divers';

    public function label(): string
    {
        return match ($this) {
            self::Cachets => 'Cachets',
            self::Technique => 'Technique',
            self::Lieu => 'Lieu',
            self::Communication => 'Communication',
            self::Accueil => 'Accueil des artistes',
            self::Buvette => 'Buvette',
            self::Billetterie => 'Billetterie',
            self::Subventions => 'Subventions et partenariats',
            self::Divers => 'Divers',
        };
    }

    /**
     * Budget sides this category may appear on.
     *
     * @return list<LigneBudgetType>
     */
    public function types(): array
    {
        return match ($this) {
            self::Cachets, self::Technique, self::Lieu, self::Communication, self::Accueil => [LigneBudgetType::Depense],
            self::Billetterie, self::Subventions => [LigneBudgetType::Recette],
            self::Buvette, self::Divers => [LigneBudgetType::Depense, LigneBudgetType::Recette],
        };
    }

    /**
     * @return list<self>
     */
    public static function pour(LigneBudgetType $type): array
    {
        return array_values(array_filter(self::cases(), static fn (self $categorie): bool => \in_array($type, $categorie->types(), true)));
    }
}
