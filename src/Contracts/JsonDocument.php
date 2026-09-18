<?php

namespace Sifrious\Molly\Contracts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class JsonDocument
{
    /** @param  array<string, mixed>  $data */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('CONTRACT_JSON_INVALID: A contract document must be a JSON object.');
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    public static function requireSchema(array $data, string $schema): void
    {
        if (($data['schema'] ?? null) !== $schema) {
            throw new InvalidArgumentException("CONTRACT_SCHEMA_INVALID: Expected {$schema}.");
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function string(array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a non-empty string.");
        }

        return $data[$key];
    }

    /** @param  array<string, mixed>  $data */
    public static function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a non-empty string or null.");
        }

        return $data[$key];
    }

    /** @param  array<string, mixed>  $data */
    public static function uuid(array $data, string $key): string
    {
        return self::assertUuid(self::string($data, $key), $key);
    }

    /** @param  array<string, mixed>  $data */
    public static function optionalUuid(array $data, string $key): ?string
    {
        $value = self::optionalString($data, $key);

        return $value === null ? null : self::assertUuid($value, $key);
    }

    public static function assertUuid(string $value, string $key): string
    {
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value)) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a UUID.");
        }

        return strtolower($value);
    }

    /** @param  array<string, mixed>  $data */
    public static function integer(array $data, string $key, int $min, int $max): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be an integer.");
        }

        $value = $data[$key];
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be between {$min} and {$max}.");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $data */
    public static function boolean(array $data, string $key): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a boolean.");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key]) || ! array_is_list($data[$key])) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a list of strings.");
        }

        $values = [];
        foreach ($data[$key] as $index => $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key}[{$index}] must be a non-empty string.");
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key]) || ! array_is_list($data[$key])) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a list of objects.");
        }

        $values = [];
        foreach ($data[$key] as $index => $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key}[{$index}] must be an object.");
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key]) || ($data[$key] !== [] && array_is_list($data[$key]))) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be an object.");
        }

        return $data[$key];
    }

    /** @param  array<string, mixed>  $data */
    public static function time(array $data, string $key): DateTimeImmutable
    {
        return self::parseTime(self::string($data, $key), $key);
    }

    public static function parseTime(string $value, string $key): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);

        if ($time === false) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be an ISO-8601 timestamp.");
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    public static function formatTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function relativePath(string $path, string $key): string
    {
        if ($path !== trim($path) || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a relative path.");
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a relative path.");
            }
        }

        return $path;
    }
}
