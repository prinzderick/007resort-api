<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable money value: decimal STRING amount (scale 4, matching DECIMAL(19,4)) + ISO currency
 * (default NGN). Uses bcmath only — never floats. Floats are rejected on construction.
 * API contract: money is serialised as a decimal string, e.g. {"amount":"1500.0000","currency":"NGN"}.
 */
final class Money implements JsonSerializable
{
    public const SCALE = 4;

    public const DEFAULT_CURRENCY = 'NGN';

    private function __construct(public readonly string $amount, public readonly string $currency) {}

    /** @param string|int $amount decimal string (e.g. "12.5") or integer. Floats are rejected. */
    public static function of(mixed $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self(self::normalize($amount), strtoupper($currency));
    }

    public static function zero(string $currency = self::DEFAULT_CURRENCY): self
    {
        return self::of('0', $currency);
    }

    /** True if the value is a valid decimal money string with at most 4 decimal places. */
    public static function isValid(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && (bool) preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', (string) $value);
    }

    /** Normalise to a fixed 4dp decimal string. Throws on floats / malformed input / >4dp. */
    public static function normalize(mixed $amount): string
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new InvalidArgumentException('Money must be a decimal string or integer, never float.');
        }
        $s = trim((string) $amount);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new InvalidArgumentException("Invalid money amount: {$s}");
        }
        if (str_contains($s, '.') && strlen(explode('.', $s)[1]) > self::SCALE) {
            throw new InvalidArgumentException('Money has more than '.self::SCALE." decimal places: {$s}");
        }
        $s = bcadd($s, '0', self::SCALE);

        return bccomp($s, '0', self::SCALE) === 0 ? bcadd('0', '0', self::SCALE) : $s; // avoid "-0.0000"
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function sub(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcsub($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    /** Multiply by a decimal-string factor (quantity, rate); result rounded half away from zero to 4dp. */
    public function mul(string|int $factor): self
    {
        return new self(self::roundHalfUp(bcmul($this->amount, (string) $factor, 12), self::SCALE), $this->currency);
    }

    /** Percentage of this amount (e.g. VAT 7.5). */
    public function percent(string $rate): self
    {
        return new self(self::roundHalfUp(bcdiv(bcmul($this->amount, $rate, 12), '100', 12), self::SCALE), $this->currency);
    }

    public function negate(): self
    {
        return new self(bcmul($this->amount, '-1', self::SCALE) === '-0.0000' ? '0.0000' : bcmul($this->amount, '-1', self::SCALE), $this->currency);
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    /** Round a decimal string half away from zero to $scale places. */
    public static function roundHalfUp(string $value, int $scale = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjust = bccomp($value, '0', 12) >= 0 ? $half : '-'.$half;

        return bcadd(bcadd($value, $adjust, $scale + 1), '0', $scale);
    }

    /** @return array{amount: string, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
