<?php

namespace App\Booking;

/**
 * What an artist sees on their shared sheet: practical information only,
 * never the fee, the payment method nor the booking status.
 */
final readonly class FeuilleArtiste
{
    /**
     * @param list<string>                                                                            $partenairesB2B other artists of the same passage
     * @param list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, artistes: list<string>}> $timetable
     */
    public function __construct(
        public string $artiste,
        public string $soiree,
        public \DateTimeImmutable $date,
        public \DateTimeImmutable $ouverture,
        public \DateTimeImmutable $fermeture,
        public ?string $lieu,
        public ?string $adresse,
        public ?string $acces,
        public ?\DateTimeImmutable $passageDebut,
        public ?\DateTimeImmutable $passageFin,
        public array $partenairesB2B,
        public array $timetable,
        public Rider $rider,
        public Hospitalite $hospitalite,
    ) {
    }
}
