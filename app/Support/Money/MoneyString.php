<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Cast for DECIMAL(19,4) money columns: always a normalised 4dp decimal STRING, never float. */
class MoneyString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Money::normalize((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Money::normalize(is_float($value) ? throw new \InvalidArgumentException('Money must not be a float.') : $value);
    }
}
