<?php

namespace App\Enum;

enum ActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Création',
            self::Updated => 'Modification',
            self::Deleted => 'Suppression',
        };
    }
}
