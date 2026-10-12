<?php

namespace App\Doctrine\Type;

use App\Booking\Rider;

/**
 * @extends JsonDtoType<Rider>
 */
final class RiderType extends JsonDtoType
{
    public const string NAME = 'rider';

    protected function dtoClass(): string
    {
        return Rider::class;
    }

    protected function fromArray(array $data): Rider
    {
        return Rider::fromArray($data);
    }

    protected function toArray(object $dto): array
    {
        return $dto->toArray();
    }
}
