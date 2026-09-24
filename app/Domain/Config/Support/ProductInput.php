<?php

namespace App\Domain\Config\Support;

use Closure;

/** Extra product fields (description, barcode, modifier groups) shared by the Catalog admin endpoints and the CSV import. */
final class ProductInput
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'modifiers' => ['sometimes', 'nullable', 'array', self::modifiers()],
        ];
    }

    /** modifiers = [{name, required?, min?, max?, options:[{name, priceDelta?}]}] */
    public static function modifiers(): Closure
    {
        return function (string $attr, mixed $v, Closure $fail): void {
            if (! is_array($v)) {
                return;
            }
            if (! array_is_list($v) || count($v) > 20) {
                $fail('modifiers must be a list of at most 20 groups.');

                return;
            }
            $names = [];
            foreach ($v as $i => $g) {
                if (! is_array($g) || ! isset($g['name']) || ! is_string($g['name']) || trim($g['name']) === '' || strlen($g['name']) > 80) {
                    $fail("modifiers.{$i}.name is required (max 80 characters).");

                    return;
                }
                if (isset($names[strtolower($g['name'])])) {
                    $fail("modifiers: group '{$g['name']}' appears twice.");

                    return;
                }
                $names[strtolower($g['name'])] = true;
                $opts = $g['options'] ?? null;
                if (! is_array($opts) || ! array_is_list($opts) || $opts === [] || count($opts) > 50) {
                    $fail("modifiers.{$i}.options must be a list of 1-50 options.");

                    return;
                }
                $seen = [];
                foreach ($opts as $j => $o) {
                    if (! is_array($o) || ! isset($o['name']) || ! is_string($o['name']) || trim($o['name']) === '' || strlen($o['name']) > 80) {
                        $fail("modifiers.{$i}.options.{$j}.name is required (max 80 characters).");

                        return;
                    }
                    if (isset($seen[strtolower($o['name'])])) {
                        $fail("modifiers.{$i}: option '{$o['name']}' appears twice.");

                        return;
                    }
                    $seen[strtolower($o['name'])] = true;
                    if (isset($o['priceDelta']) && (! is_string($o['priceDelta']) || ! preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $o['priceDelta']))) {
                        $fail("modifiers.{$i}.options.{$j}.priceDelta must be a decimal string.");

                        return;
                    }
                }
                foreach (['min', 'max'] as $k) {
                    if (isset($g[$k]) && (! is_int($g[$k]) || $g[$k] < 0 || $g[$k] > count($opts))) {
                        $fail("modifiers.{$i}.{$k} must be a whole number between 0 and the number of options.");

                        return;
                    }
                }
                if (isset($g['min'], $g['max']) && $g['min'] > $g['max']) {
                    $fail("modifiers.{$i}: min cannot exceed max.");

                    return;
                }
                if (isset($g['required']) && ! is_bool($g['required'])) {
                    $fail("modifiers.{$i}.required must be true or false.");

                    return;
                }
            }
        };
    }
}
