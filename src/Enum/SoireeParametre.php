<?php

namespace App\Enum;

/**
 * Settings of a soirée whose change publishes App\Event\SoireeParametresModifies.
 * Values are the Soiree property names.
 */
enum SoireeParametre: string
{
    case Date = 'date';
    case Jauge = 'jauge';
    case Lieu = 'lieu';
    case Buvette = 'buvette';
}
