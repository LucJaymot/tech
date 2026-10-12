<?php

namespace App\Planning;

/**
 * What a volunteer sees on their personal page: the soirée, their own
 * assignments, and the créneaux they can say they are available for.
 * Never the other volunteers.
 */
final readonly class VueEspaceBenevole
{
    /**
     * @param list<array{poste: string, debut: \DateTimeImmutable, fin: \DateTimeImmutable}>                                           $affectations
     * @param array<string, list<array{id: int, debut: \DateTimeImmutable, fin: \DateTimeImmutable, disponible: bool, affecte: bool}>> $creneauxParPoste
     */
    public function __construct(
        public string $benevole,
        public string $soiree,
        public \DateTimeImmutable $date,
        public \DateTimeImmutable $ouverture,
        public \DateTimeImmutable $fermeture,
        public ?string $lieu,
        public ?string $adresse,
        public ?string $acces,
        public array $affectations,
        public array $creneauxParPoste,
        /** Availabilities can no longer change once the soirée is over. */
        public bool $modifiable,
    ) {
    }
}
