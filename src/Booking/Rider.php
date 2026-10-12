<?php

namespace App\Booking;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Technical rider of a booking, stored as JSON (see App\Doctrine\Type\RiderType).
 * Immutable: use the with*() methods, so that Doctrine sees the change.
 */
final readonly class Rider
{
    /**
     * @param list<string> $materielDemande gear the venue must provide
     * @param list<string> $materielApporte gear the artist brings
     */
    public function __construct(
        #[Assert\PositiveOrZero]
        public int $installationMinutes = 0,
        #[Assert\PositiveOrZero]
        public int $balanceMinutes = 0,
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 200)])]
        public array $materielDemande = [],
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 200)])]
        public array $materielApporte = [],
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
            JsonPayload::int($data, 'installationMinutes'),
            JsonPayload::int($data, 'balanceMinutes'),
            JsonPayload::strings($data, 'materielDemande'),
            JsonPayload::strings($data, 'materielApporte'),
            JsonPayload::nullableString($data, 'notes'),
        );
    }

    /**
     * @return array{installationMinutes: int, balanceMinutes: int, materielDemande: list<string>, materielApporte: list<string>, notes: ?string}
     */
    public function toArray(): array
    {
        return [
            'installationMinutes' => $this->installationMinutes,
            'balanceMinutes' => $this->balanceMinutes,
            'materielDemande' => $this->materielDemande,
            'materielApporte' => $this->materielApporte,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param list<string> $materielDemande
     */
    public function withMaterielDemande(array $materielDemande): self
    {
        return new self($this->installationMinutes, $this->balanceMinutes, $materielDemande, $this->materielApporte, $this->notes);
    }

    public function withTemps(int $installationMinutes, int $balanceMinutes): self
    {
        return new self($installationMinutes, $balanceMinutes, $this->materielDemande, $this->materielApporte, $this->notes);
    }
}
