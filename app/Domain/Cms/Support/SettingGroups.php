<?php

namespace App\Domain\Cms\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Validation + seeded defaults of the grouped site settings (docs/CMS_API.md 4.2). Unknown keys are dropped. */
final class SettingGroups
{
    public const DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    /** @return array<string, list<mixed>> */
    public static function rules(string $group): array
    {
        $s = fn (int $max = 200) => ['nullable', 'string', 'max:'.$max];
        $url = ['nullable', 'url:http,https', 'max:500'];

        return match ($group) {
            'brand' => ['name' => ['required', 'string', 'max:120'], 'tagline' => $s(200), 'logoMediaId' => ['nullable', new MediaExists]],
            'contact' => [
                'phone' => $s(40), 'whatsapp' => $s(40), 'email' => ['nullable', 'email:rfc', 'max:190'], 'address' => $s(300), 'mapEmbedUrl' => $url,
                'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'],
            ],
            'hours' => [
                'weekly' => ['required', 'array', 'size:7'], 'weekly.*.day' => ['required', Rule::in(self::DAYS), 'distinct'], 'weekly.*.closed' => ['nullable', 'boolean'],
                'weekly.*.open' => ['nullable', 'date_format:H:i'], 'weekly.*.close' => ['nullable', 'date_format:H:i'], 'notes' => $s(500),
                'holidays' => ['nullable', 'array', 'max:60'], 'holidays.*.date' => ['required', 'date_format:Y-m-d'], 'holidays.*.label' => ['required', 'string', 'max:80'],
                'holidays.*.closed' => ['nullable', 'boolean'], 'holidays.*.open' => ['nullable', 'date_format:H:i'], 'holidays.*.close' => ['nullable', 'date_format:H:i'],
            ],
            'social' => ['instagram' => $url, 'facebook' => $url, 'x' => $url, 'tiktok' => $url, 'youtube' => $url],
            'seo' => ['titleTemplate' => $s(120), 'defaultTitle' => $s(70), 'defaultDescription' => $s(200), 'ogImageMediaId' => ['nullable', new MediaExists]],
            'announcement' => ['enabled' => ['required', 'boolean'], 'text' => ['nullable', 'string', 'max:240', 'required_if:enabled,true'], 'link' => ['nullable', new SafeLink], 'tone' => ['nullable', Rule::in(['INFO', 'PROMO', 'WARNING'])]],
            'booking' => ['ticketsCtaLabel' => $s(40), 'bookingCtaLabel' => $s(40), 'membershipCtaLabel' => $s(40), 'eventsCtaLabel' => $s(40)],
            'footer' => ['text' => $s(500), 'copyright' => $s(200)],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function validate(string $group, array $value): array
    {
        $clean = self::trimStrings($value);
        $v = Validator::make($clean, self::rules($group))->validate();

        return self::normalise($group, $v);
    }

    /** @param array<string, mixed> $v @return array<string, mixed> */
    private static function normalise(string $group, array $v): array
    {
        if ($group === 'hours') {
            $days = [];
            foreach ($v['weekly'] as $d) {
                $closed = (bool) ($d['closed'] ?? false);
                $days[$d['day']] = ['day' => $d['day'], 'open' => $closed ? null : ($d['open'] ?? null), 'close' => $closed ? null : ($d['close'] ?? null), 'closed' => $closed];
            }
            $weekly = array_map(fn ($day) => $days[$day], self::DAYS);
            $holidays = array_map(fn ($h) => ['date' => $h['date'], 'label' => $h['label'], 'open' => $h['open'] ?? null, 'close' => $h['close'] ?? null, 'closed' => (bool) ($h['closed'] ?? false)], $v['holidays'] ?? []);
            usort($holidays, fn ($a, $b) => strcmp($a['date'], $b['date']));

            return ['weekly' => $weekly, 'notes' => $v['notes'] ?? null, 'holidays' => $holidays];
        }
        if ($group === 'announcement') {
            return ['enabled' => (bool) $v['enabled'], 'text' => $v['text'] ?? null, 'link' => $v['link'] ?? null, 'tone' => $v['tone'] ?? 'INFO'];
        }
        $out = [];
        foreach (array_keys(self::rules($group)) as $key) {
            if (! str_contains($key, '.')) {
                $val = $v[$key] ?? null;
                $out[$key] = in_array($key, ['lat', 'lng'], true) && $val !== null ? (float) $val : $val;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function trimStrings(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_string($v)) {
                $a[$k] = trim($v) === '' ? null : trim($v);
            } elseif (is_array($v)) {
                $a[$k] = self::trimStrings($v);
            }
        }

        return $a;
    }

    /** @return array<string, array<string, mixed>> group => default value */
    public static function defaults(): array
    {
        $weekly = array_map(fn ($d) => ['day' => $d, 'open' => in_array($d, ['SAT', 'SUN'], true) ? '09:00' : '08:00', 'close' => in_array($d, ['FRI', 'SAT'], true) ? '23:00' : '22:00', 'closed' => false], self::DAYS);

        return [
            'brand' => ['name' => '007 Resort & Spa', 'tagline' => 'Play. Splash. Reset. Feast.', 'logoMediaId' => null],
            'contact' => ['phone' => '+234 800 007 0007', 'whatsapp' => '+234 800 007 0007', 'email' => 'hello@007resort.demo.test', 'address' => 'Otueke, Bayelsa State, Nigeria', 'mapEmbedUrl' => null, 'lat' => 4.9375, 'lng' => 6.2634],
            'hours' => ['weekly' => $weekly, 'notes' => 'Kitchen closes one hour before closing time.', 'holidays' => [['date' => '2026-12-25', 'label' => 'Christmas Day', 'open' => '10:00', 'close' => '18:00', 'closed' => false], ['date' => '2027-01-01', 'label' => "New Year's Day", 'open' => '12:00', 'close' => '23:00', 'closed' => false]]],
            'social' => ['instagram' => 'https://instagram.com/007resort', 'facebook' => 'https://facebook.com/007resort', 'x' => null, 'tiktok' => null, 'youtube' => null],
            'seo' => ['titleTemplate' => '%s | 007 Resort & Spa', 'defaultTitle' => '007 Resort & Spa - Play, splash, reset and feast', 'defaultDescription' => 'Sports arena, pool, spa, restaurant and bars in one destination in Bayelsa.', 'ogImageMediaId' => null],
            'announcement' => ['enabled' => false, 'text' => null, 'link' => null, 'tone' => 'INFO'],
            'booking' => ['ticketsCtaLabel' => 'Buy tickets', 'bookingCtaLabel' => 'Book a court', 'membershipCtaLabel' => 'Join the club', 'eventsCtaLabel' => 'Get tickets'],
            'footer' => ['text' => 'Your weekend escape in the heart of Bayelsa.', 'copyright' => '007 Resort & Spa. All rights reserved.'],
        ];
    }
}
