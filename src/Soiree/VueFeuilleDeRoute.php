<?php

namespace App\Soiree;

use App\Obligations\CheckSoiree;

/**
 * Everything the crew needs on the night, as plain values: nothing left to
 * load while rendering.
 */
final readonly class VueFeuilleDeRoute
{
    /**
     * @param list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, artistes: list<string>}>                                                                        $timetable
     * @param list<array{poste: string, creneaux: list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, benevoles: list<array{nom: string, telephone: ?string}>}>}> $equipe
     * @param list<array{nom: string, telephone: ?string, email: ?string}>                                                                                                   $artistes
     * @param list<CheckSoiree>                                                                                                                                              $checks
     */
    public function __construct(
        public int $id,
        public string $soiree,
        public \DateTimeImmutable $ouverture,
        public \DateTimeImmutable $fermeture,
        public ?int $jauge,
        public ?string $lieu,
        public ?string $adresse,
        public ?string $acces,
        public array $timetable,
        public array $equipe,
        public array $artistes,
        public array $checks,
    ) {
    }
}
