<?php

namespace App\Enum;

/**
 * How an artist is paid. Only these declared forms are allowed: no cash
 * payment and no self-employed (auto-entrepreneur) invoice.
 */
enum ModeRemuneration: string
{
    /** Declared through the GUSO (Guichet unique du spectacle occasionnel). */
    case Guso = 'guso';
    /** Contrat de cession with the artist's production structure. */
    case Cession = 'cession';
    /** Payroll through a structure that employs the artist. */
    case Paie = 'paie';

    public function label(): string
    {
        return match ($this) {
            self::Guso => 'GUSO',
            self::Cession => 'Contrat de cession',
            self::Paie => 'Paie',
        };
    }
}
