<?php

namespace App\Enum;

/**
 * Places of the "soiree" workflow (config/packages/workflow.yaml).
 */
enum SoireeStatut: string
{
    case Preparation = 'preparation';
    case Confirmee = 'confirmee';
    case Terminee = 'terminee';
    case BilanFait = 'bilan_fait';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => 'En préparation',
            self::Confirmee => 'Confirmée',
            self::Terminee => 'Terminée',
            self::BilanFait => 'Bilan fait',
        };
    }
}
