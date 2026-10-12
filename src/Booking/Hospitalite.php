<?php

namespace App\Booking;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Hospitality owed to an artist, stored as JSON (see App\Doctrine\Type\HospitaliteType).
 * Immutable: build a new instance to change it, so that Doctrine sees the change.
 */
final readonly class Hospitalite
{
    /**
     * @param list<string> $regimes dietary requirements, e.g. "végétarien"
     */
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $repas = 0,
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 100)])]
        public array $regimes = [],
        /** Guest-list places. */
        #[Assert\PositiveOrZero]
        public int $invitations = 0,
        public bool $hebergement = false,
        #[Assert\Length(max: 2000)]
        public ?string $notes = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException when a value has the wrong type
     */
    public static function fromArray(array $data): self
    {
        return new self(
            JsonPayload::int($data, 'repas'),
            JsonPayload::strings($data, 'regimes'),
            JsonPayload::int($data, 'invitations'),
            JsonPayload::bool($data, 'hebergement'),
            JsonPayload::nullableString($data, 'notes'),
        );
    }

    /**
     * @return array{repas: int, regimes: list<string>, invitations: int, hebergement: bool, notes: ?string}
     */
    public function toArray(): array
    {
        return [
            'repas' => $this->repas,
            'regimes' => $this->regimes,
            'invitations' => $this->invitations,
            'hebergement' => $this->hebergement,
            'notes' => $this->notes,
        ];
    }
}
