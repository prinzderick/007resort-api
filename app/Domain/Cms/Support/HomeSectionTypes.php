<?php

namespace App\Domain\Cms\Support;

use Illuminate\Support\Facades\Validator;

/** Typed payload schemas of the home page blocks (docs/CMS_API.md 4.3). */
final class HomeSectionTypes
{
    public const TYPES = ['HERO_SLIDE', 'HIGHLIGHT', 'STAT', 'TESTIMONIAL', 'FAQ', 'PARTNER', 'CTA_BAND'];

    /** @return list<Field> */
    public static function fields(string $type): array
    {
        return match ($type) {
            'HERO_SLIDE' => [
                new Field('headline', 'string', true, 140), new Field('subheadline', 'string', false, 300), new Field('mediaId', 'media', true),
                new Field('ctaLabel', 'string', false, 40), new Field('ctaLink', 'link'), new Field('alignment', 'enum', false, null, ['LEFT', 'CENTER', 'RIGHT']),
            ],
            'HIGHLIGHT' => [
                new Field('title', 'string', true, 80), new Field('blurb', 'text', true, 400), new Field('priceFrom', 'string', false, 60), new Field('mediaId', 'media'),
                new Field('link', 'link'), new Field('category', 'enum', true, null, ['play', 'splash', 'reset', 'feast']),
            ],
            'STAT' => [new Field('label', 'string', true, 60), new Field('value', 'string', true, 20), new Field('suffix', 'string', false, 20), new Field('icon', 'string', false, 40)],
            'TESTIMONIAL' => [
                new Field('name', 'string', true, 80), new Field('role', 'string', false, 80), new Field('quote', 'text', true, 600),
                new Field('rating', 'int', false, null, [1, 5]), new Field('avatarMediaId', 'media'),
            ],
            'FAQ' => [new Field('question', 'string', true, 200), new Field('answer', 'markdown', true, 2000), new Field('topic', 'string', false, 60)],
            'PARTNER' => [new Field('name', 'string', true, 80), new Field('logoMediaId', 'media'), new Field('link', 'link')],
            'CTA_BAND' => [
                new Field('title', 'string', true, 120), new Field('text', 'string', false, 300), new Field('ctaLabel', 'string', true, 40),
                new Field('ctaLink', 'link', true), new Field('mediaId', 'media'),
            ],
            default => [],
        };
    }

    /** @return array<string, list<mixed>> */
    public static function rules(string $type, string $prefix = 'payload'): array
    {
        $rules = [$prefix => ['required', 'array']];
        foreach (self::fields($type) as $f) {
            $rules["{$prefix}.{$f->name}"] = $f->rules();
        }

        return $rules;
    }

    /**
     * Validate + normalise a payload for its type (unknown keys are dropped, empty strings become null, defaults applied).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validate(string $type, array $payload): array
    {
        $clean = [];
        foreach ($payload as $k => $v) {
            $clean[$k] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        }
        $validated = Validator::make(['payload' => $clean], self::rules($type))->validate()['payload'] ?? [];
        $out = [];
        foreach (self::fields($type) as $f) {
            $v = $validated[$f->name] ?? null;
            $out[$f->name] = $v ?? ($f->name === 'alignment' ? 'LEFT' : null);
        }

        return $out;
    }

    /** @return array<string, array{fields: list<array<string, mixed>>}> */
    public static function describe(): array
    {
        $out = [];
        foreach (self::TYPES as $t) {
            $out[$t] = ['fields' => array_map(fn (Field $f) => $f->describe(), self::fields($t))];
        }

        return $out;
    }
}
