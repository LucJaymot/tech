<?php

namespace App\Doctrine\Type;

use App\Booking\Hospitalite;

/**
 * @extends JsonDtoType<Hospitalite>
 */
final class HospitaliteType extends JsonDtoType
{
    public const string NAME = 'hospitalite';

    protected function dtoClass(): string
    {
        return Hospitalite::class;
    }

    protected function fromArray(array $data): Hospitalite
    {
        return Hospitalite::fromArray($data);
    }

    protected function toArray(object $dto): array
    {
        return $dto->toArray();
    }
}
