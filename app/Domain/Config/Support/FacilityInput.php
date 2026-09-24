<?php

namespace App\Domain\Config\Support;

use Closure;

/** Validation rules shared by facility create/update (opening hours, contact, timezone). */
final class FacilityInput
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const KIND_PATTERN = '/^[A-Z][A-Z0-9_]{1,31}$/';

    /** @return array<string, list<mixed>> */
    public static function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'code' => $create ? ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,63}$/'] : ['prohibited'],
            'name' => [$req, 'string', 'min:1', 'max:200'],
            'kind' => ['sometimes', 'string', 'regex:'.self::KIND_PATTERN],
            'parentId' => $create ? ['sometimes', 'nullable', 'uuid'] : ['prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', fn ($a, $v, $fail) => $v === null || in_array($v, \DateTimeZone::listIdentifiers(), true) || $fail('Unknown time zone (use an IANA name such as Africa/Lagos).')],
            'sortOrder' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
            'active' => $create ? ['sometimes', 'boolean'] : ['prohibited'],
            'contact' => ['sometimes', 'nullable', 'array'],
            'contact.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'contact.email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'contact.address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact.managerName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'openingHours' => ['sometimes', 'nullable', 'array', self::openingHours()],
            'templateKey' => $create ? ['sometimes', 'nullable', 'string', 'max:40'] : ['prohibited'],
            'capabilities' => $create ? ['sometimes', 'array'] : ['prohibited'],
            'capabilities.*' => ['string', 'max:48'],
            'operatingRules' => $create ? ['sometimes', 'array'] : ['prohibited'],
            'applyStarter' => $create ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    /**
     * openingHours = { weekly: {mon: [{open:"08:00", close:"22:00"}], ... (missing/empty day = closed)},
     *                  exceptions: [{date:"2026-12-25", closed:true|false, windows:[{open,close}], note?}] }
     */
    public static function openingHours(): Closure
    {
        return function (string $attr, mixed $v, Closure $fail): void {
            if (! is_array($v)) {
                return;
            }
            foreach (array_keys($v) as $k) {
                if (! in_array($k, ['weekly', 'exceptions'], true)) {
                    $fail("openingHours.{$k} is not allowed (use weekly and exceptions).");

                    return;
                }
            }
            foreach (($v['weekly'] ?? []) as $day => $windows) {
                if (! in_array($day, self::DAYS, true)) {
                    $fail("openingHours.weekly.{$day}: day must be one of ".implode(', ', self::DAYS).'.');

                    return;
                }
                if (($err = self::windowsError($windows)) !== null) {
                    $fail("openingHours.weekly.{$day}: {$err}");

                    return;
                }
            }
            $seen = [];
            foreach (($v['exceptions'] ?? []) as $i => $ex) {
                $date = is_array($ex) ? ($ex['date'] ?? null) : null;
                if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                    $fail("openingHours.exceptions.{$i}.date must be a valid date (YYYY-MM-DD).");

                    return;
                }
                if (isset($seen[$date])) {
                    $fail("openingHours.exceptions: {$date} appears twice.");

                    return;
                }
                $seen[$date] = true;
                if (! isset($ex['closed']) || ! is_bool($ex['closed'])) {
                    $fail("openingHours.exceptions.{$i}.closed must be true or false.");

                    return;
                }
                if (! $ex['closed'] && ($err = self::windowsError($ex['windows'] ?? null, true)) !== null) {
                    $fail("openingHours.exceptions.{$i}.windows: {$err}");

                    return;
                }
                if (isset($ex['note']) && (! is_string($ex['note']) || strlen($ex['note']) > 255)) {
                    $fail("openingHours.exceptions.{$i}.note must be text up to 255 characters.");

                    return;
                }
            }
        };
    }

    private static function windowsError(mixed $windows, bool $required = false): ?string
    {
        if ($windows === null && ! $required) {
            return null;
        }
        if (! is_array($windows) || ! array_is_list($windows) || ($required && $windows === [])) {
            return 'must be a list of {open, close} windows.';
        }
        $ranges = [];
        foreach ($windows as $w) {
            $o = is_array($w) ? ($w['open'] ?? null) : null;
            $c = is_array($w) ? ($w['close'] ?? null) : null;
            $ok = fn ($t) => is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $t) === 1;
            if (! $ok($o) || ! $ok($c) || $o === '24:00') {
                return 'times must be HH:MM (24h; close may be 24:00).';
            }
            if ($c <= $o) {
                return 'close must be after open (split overnight hours across two days).';
            }
            foreach ($ranges as [$ro, $rc]) {
                if ($o < $rc && $c > $ro) {
                    return 'windows must not overlap.';
                }
            }
            $ranges[] = [$o, $c];
        }

        return null;
    }
}
