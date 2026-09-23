<?php

namespace App\Domain\Inventory\Support;

use InvalidArgumentException;

/** Quantity arithmetic on decimal STRINGS (DECIMAL(19,4)); bcmath only, never floats. */
final class Qty
{
    public const SCALE = 4;

    public static function normalize(mixed $q): string
    {
        if (! is_string($q) && ! is_int($q)) {
            throw new InvalidArgumentException('Quantity must be a decimal string or integer, never float.');
        }
        $s = trim((string) $q);
        if (! preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $s)) {
            throw new InvalidArgumentException("Invalid quantity: {$s}");
        }

        return bcadd($s, '0', self::SCALE);
    }

    public static function isValid(mixed $q): bool
    {
        return (is_string($q) || is_int($q)) && (bool) preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', (string) $q);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function neg(string $a): string
    {
        return bcmul($a, '-1', self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isPositive(string $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function isNegative(string $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function abs(string $a): string
    {
        return self::isNegative($a) ? self::neg($a) : self::normalize($a);
    }
}
