<?php

namespace App\Domain\Cms\Services;

use App\Support\Sync\Outbox;

/**
 * Outbox hook for the two CMS sync events (docs/CMS_API.md section 7). Off by default (`CMS_SYNC_EMIT=false`) until the receiving
 * appliers exist: an event type with no applier is stored FAILED on the peer. Must run inside the business transaction.
 */
final class CmsSync
{
    public static function enabled(): bool
    {
        return (bool) config('cms.sync.emit', false);
    }

    /** @param array<string, mixed>|null $snapshot */
    public static function content(string $entityType, string $id, string $action, int $version, ?array $snapshot = null): void
    {
        if (! self::enabled()) {
            return;
        }
        Outbox::record('CmsContentPublished', $entityType, $id, ['entity' => $entityType, 'id' => $id, 'action' => $action, 'snapshot' => $snapshot], $version);
    }

    /** @param array<string, mixed> $row @param list<array<string, mixed>> $variants */
    public static function mediaUploaded(string $id, array $row, array $variants): void
    {
        if (! self::enabled()) {
            return;
        }
        Outbox::record('CmsMediaUploaded', 'cms_media', $id, [
            'id' => $id, 'path' => $row['path'], 'sha256' => $row['sha256'], 'width' => $row['width'], 'height' => $row['height'], 'mimeType' => $row['mime_type'],
            'alt' => $row['alt'], 'credit' => $row['credit'], 'sourceUrl' => $row['source_url'], 'tags' => json_decode($row['tags'], true), 'variants' => $variants,
        ]);
    }
}
