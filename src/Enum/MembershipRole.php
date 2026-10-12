<?php

namespace App\Enum;

enum MembershipRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Reader = 'reader';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Member => 'Membre',
            self::Reader => 'Lecteur',
        };
    }
}
