<?php

namespace App\Event;

use App\Enum\SoireeParametre;

/**
 * Published once a soirée's date, jauge, lieu or buvette has changed and been
 * saved. The rétroplanning listens to it to reschedule its tasks.
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
