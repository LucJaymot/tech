<?php

namespace App\Enum;

/**
 * Places of the "booking" workflow (config/packages/workflow.yaml).
 */
enum BookingStatut: string
{
    case Contacte = 'contacte';
    case Negociation = 'negociation';
    case Confirme = 'confirme';
    case ContratSigne = 'contrat_signe';
    case DeclareGuso = 'declare_guso';
    case Paye = 'paye';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Contacte => 'Contacté',
            self::Negociation => 'En négociation',
            self::Confirme => 'Confirmé',
            self::ContratSigne => 'Contrat signé',
            self::DeclareGuso => 'Déclaré au GUSO',
            self::Paye => 'Payé',
            self::Annule => 'Annulé',
        };
    }
}
