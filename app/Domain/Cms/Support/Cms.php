<?php

namespace App\Domain\Cms\Support;

final class Cms
{
    public const STATUSES = ['DRAFT', 'PUBLISHED', 'ARCHIVED'];

    public const EVENT_CATEGORIES = ['SPORT', 'MUSIC', 'PARTY', 'WELLNESS', 'FOOD', 'OTHER'];

    public const SETTING_GROUPS = ['brand', 'contact', 'hours', 'social', 'seo', 'announcement', 'booking', 'footer'];

    public const CONTACT_TOPICS = ['GENERAL', 'BOOKING', 'EVENTS', 'MEMBERSHIP', 'FEEDBACK', 'PRESS', 'OTHER'];

    public const CONTACT_STATUSES = ['NEW', 'READ', 'REPLIED', 'SPAM'];

    public const SUBSCRIBER_SOURCES = ['footer', 'blog', 'event', 'popup', 'checkout'];

    public const SUBSCRIBER_STATUSES = ['PENDING', 'CONFIRMED', 'UNSUBSCRIBED'];

    public const MEDIA_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public const MEDIA_MAX_BYTES = 8388608;

    public static function tz(): string
    {
        return (string) config('cms.timezone', 'Africa/Lagos');
    }
}
