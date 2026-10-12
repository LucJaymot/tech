<?php

namespace App\Entity;

/**
 * An entity that belongs to a single collective. The "collective" Doctrine
 * filter restricts every query on it to the active collective.
 *
 * Implementations must map a ManyToOne association named "collective".
 */
interface CollectiveScopedInterface
{
    public function getCollective(): Collective;
}
