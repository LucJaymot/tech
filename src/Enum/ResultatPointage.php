<?php

namespace App\Enum;

/**
 * Outcome of one check-in sent by a door device.
 */
enum ResultatPointage: string
{
    /** Saved: first check-in of this guest, or earlier than the one saved. */
    case Accepte = 'accepte';
    /** Already saved with this same id: the device sent it twice. */
    case DejaEnregistre = 'deja_enregistre';
    /** Another check-in of this guest happened first and wins. */
    case Conflit = 'conflit';
    /** Unknown guest, malformed id or time. */
    case Invalide = 'invalide';
}
