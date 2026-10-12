<?php

namespace App\Budget;

/**
 * An estimated charge computed from a configured rate.
 */
final readonly class Estimation
{
    public function __construct(
        public TauxEstime $taux,
        public int $prevu,
        public int $reel,
    ) {
    }
}
