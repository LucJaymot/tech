<?php

namespace App\Visuel;

/**
 * What the public page of a soirée may show, and nothing else: no budget,
 * contact, guest list nor access notes (they may hold door codes).
 */
final readonly class VuePublique
{
    /**
     * @param list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, artistes: list<string>}> $timetable
     */
    public function __construct(
        public string $slug,
        public string $soiree,
        public string $collectif,
        public string $accent,
        public \DateTimeImmutable $date,
        public \DateTimeImmutable $ouverture,
        public \DateTimeImmutable $fermeture,
        public ?string $lieu,
        public ?string $adresse,
        public array $timetable,
        /** Unix time of the square visual, null until generated. */
        public ?int $visuelGenereLe,
    ) {
    }
}
