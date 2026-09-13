<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use RuntimeException;

/**
 * A contract's secret material, in memory, for the duration of one call.
 *
 * Deliberately NOT a model attribute passed around: a value object with a private array is the
 * cheapest way to make "never render, never log" true rather than intended. It has no
 * `toArray()`, no `__toString()`, no public property and no getter that returns everything — a
 * caller must ask for one named key, and `__debugInfo()` hides the values from `dd()`, `var_dump()`
 * and every dump-driven log line (AGENTS §3).
 */
final class ProviderCredentials
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values) {}

    /** @param array<string, mixed>|null $raw the decrypted `credentials` column */
    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): self
    {
        $values = [];
        foreach ($raw ?? [] as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /** @throws RuntimeException when the contract does not carry that key */
    public function require(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (! is_string($value) || $value === '') {
            // Names the KEY, never a value: this message reaches a log and a screen.
            throw new RuntimeException("This payment contract has no [{$key}] configured.");
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    /**
     * Which keys are present — for the dashboard's "set / not set" column.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (int|string $k): string => (string) $k, array_keys($this->values));
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * What a dump shows. The values are the one thing this object exists to hold and the one thing
     * nothing may print, so a dump gets the key names and a count.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['keys' => $this->keys(), 'values' => '[redacted]'];
    }
}
