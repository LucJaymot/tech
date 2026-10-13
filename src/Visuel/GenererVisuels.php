<?php

namespace App\Visuel;

/**
 * Asks App\Visuel\VisuelsLineUp to (re)generate a soirée's line-up visuals.
 */
final readonly class GenererVisuels
{
    public function __construct(
        public int $soireeId,
    ) {
    }
}
