<?php

namespace App\Booking;

/**
 * Strict readers for the JSON documents behind Rider and Hospitalite:
 * a missing key falls back to its default, a value of the wrong type is an error.
 */
final class JsonPayload
{
    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? $default;

        return \is_int($value) ? $value : throw self::invalid($key, 'an integer');
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;

        return \is_bool($value) ? $value : throw self::invalid($key, 'a boolean');
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return null === $value || \is_string($value) ? $value : throw self::invalid($key, 'a string or null');
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::invalid($key, 'a list of strings');
        }

        $strings = [];
        foreach ($value as $item) {
            $strings[] = \is_string($item) ? $item : throw self::invalid($key, 'a list of strings');
        }

        return $strings;
    }

    private static function invalid(string $key, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('"%s" must be %s.', $key, $expected));
    }
}
