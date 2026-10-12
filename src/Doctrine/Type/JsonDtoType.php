<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;

/**
 * A JSON column (jsonb on PostgreSQL) holding one typed, immutable DTO.
 * The DTO exposes fromArray() and toArray(); no free array reaches the entity.
 *
 * @template T of object
 */
abstract class JsonDtoType extends JsonType
{
    /**
     * @return class-string<T>
     */
    abstract protected function dtoClass(): string;

    /**
     * @param array<mixed> $data
     *
     * @return T
     */
    abstract protected function fromArray(array $data): object;

    /**
     * @param T $dto
     *
     * @return array<mixed>
     */
    abstract protected function toArray(object $dto): array;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $class = $this->dtoClass();
        if (!$value instanceof $class) {
            throw new \InvalidArgumentException(\sprintf('Expected an instance of %s, got %s.', $class, get_debug_type($value)));
        }

        return parent::convertToDatabaseValue($this->toArray($value), $platform);
    }

    /**
     * @return T|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?object
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }
        if (!\is_array($data)) {
            throw new ConversionException(\sprintf('A %s must be stored as a JSON object.', $this->dtoClass()));
        }

        try {
            return $this->fromArray($data);
        } catch (\UnexpectedValueException $e) {
            throw new ConversionException(\sprintf('Invalid %s in the database: %s', $this->dtoClass(), $e->getMessage()), previous: $e);
        }
    }
}
