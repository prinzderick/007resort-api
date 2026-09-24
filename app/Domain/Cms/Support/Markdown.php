<?php

namespace App\Domain\Cms\Support;

use Illuminate\Support\Str;

/** Markdown is the stored format; HTML is rendered server-side with raw HTML stripped and unsafe links removed. */
final class Markdown
{
    public static function html(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return trim(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]));
    }

    public static function plain(?string $markdown, int $limit = 200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(self::html($markdown))) ?? '');

        return Str::limit($text, $limit, '…');
    }

    public static function readingMinutes(?string $markdown): int
    {
        $words = str_word_count(strip_tags(self::html($markdown)));

        return max(1, (int) ceil($words / 220));
    }
}
