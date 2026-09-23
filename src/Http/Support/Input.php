<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\ApiException;
use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reader for a JSON request body. Slim's BodyParsingMiddleware has
 * already decoded it; this validates shape and pulls fields, raising a clean
 * 422 (`validation_failed`, with a `field` detail) instead of letting a bad
 * type surface deep in a service.
 */
final class Input
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            throw new ApiException(422, 'invalid_body', 'Request body must be a JSON object.');
        }

        return new self($parsed);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    // --- strings ------------------------------------------------------------

    public function requiredString(string $key, int $max = 1000): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            $this->fail($key, "`{$key}` is required.");
        }

        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $this->fail($key, "`{$key}` must be at most {$max} characters.");
        }

        return $value;
    }

    public function optionalString(string $key, int $max = 1000): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            return null;
        }

        $value = $this->data[$key];
        if (!is_string($value)) {
            $this->fail($key, "`{$key}` must be a string.");
        }

        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $this->fail($key, "`{$key}` must be at most {$max} characters.");
        }

        return $value;
    }

    // --- enums ------------------------------------------------------------

    /** @param list<string> $allowed */
    public function requiredEnum(string $key, array $allowed): string
    {
        $value = $this->requiredString($key, 100);

        if (!in_array($value, $allowed, true)) {
            $this->fail($key, "`{$key}` must be one of: " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    /** @param list<string> $allowed */
    public function optionalEnum(string $key, array $allowed): ?string
    {
        $value = $this->optionalString($key, 100);

        if ($value !== null && !in_array($value, $allowed, true)) {
            $this->fail($key, "`{$key}` must be one of: " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    // --- numbers -----------------------------------------------------------

    public function requiredNumber(string $key, ?float $min = null, ?float $max = null): float
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value) && !is_float($value) && (!is_string($value) || !is_numeric($value))) {
            $this->fail($key, "`{$key}` must be a number.");
        }

        $number = (float) $value;

        if ($min !== null && $number < $min) {
            $this->fail($key, "`{$key}` must be at least {$min}.");
        }
        if ($max !== null && $number > $max) {
            $this->fail($key, "`{$key}` must be at most {$max}.");
        }

        return $number;
    }

    // --- specific formats ------------------------------------------------

    public function requiredEmail(string $key): string
    {
        $value = $this->requiredString($key, 190);

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->fail($key, "`{$key}` must be a valid email address.");
        }

        return strtolower($value);
    }

    public function optionalEmail(string $key): ?string
    {
        $value = $this->optionalString($key, 190);

        if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->fail($key, "`{$key}` must be a valid email address.");
        }

        return $value !== null ? strtolower($value) : null;
    }

    /** ISO-4217-ish: exactly three ASCII letters. Not checked against a live list. */
    public function requiredCurrency(string $key): string
    {
        $value = strtoupper($this->requiredString($key, 3));

        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            $this->fail($key, "`{$key}` must be a 3-letter currency code.");
        }

        return $value;
    }

    /** Accepts an ISO-8601 datetime (or `YYYY-MM-DD HH:MM:SS`). Returns UTC. */
    public function optionalDateTime(string $key): ?DateTimeImmutable
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            return null;
        }

        $value = $this->data[$key];
        if (!is_string($value)) {
            $this->fail($key, "`{$key}` must be a datetime string.");
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $this->fail($key, "`{$key}` is not a valid datetime.");
        }
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiException(422, 'validation_failed', $message, ['field' => $field]);
    }
}
