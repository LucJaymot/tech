<?php

namespace App\Event;

use App\Enum\SoireeParametre;

/**
 * Published once a soirée's date, jauge, lieu, buvette or artistesPayes has
 * changed and been saved. Also a Messenger message: App\Obligations\RetroplanningGenerator
 * handles it asynchronously to reschedule the soirée's tasks.
 */
final readonly class SoireeParametresModifies
{
    /**
     * @param non-empty-list<SoireeParametre> $parametres what changed
     */
    public function __construct(
        public int $soireeId,
        public int $collectiveId,
        public array $parametres,
    ) {
    }

    public function concerne(SoireeParametre $parametre): bool
    {
        return \in_array($parametre, $this->parametres, true);
    }
}
