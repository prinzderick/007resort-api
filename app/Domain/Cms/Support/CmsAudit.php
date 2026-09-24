<?php

namespace App\Domain\Cms\Support;

use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenant;

/** Audit::record for CMS writes. Skipped only when no organization/site exists at all (seeding a bare database). */
final class CmsAudit
{
    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new */
    public static function record(string $action, string $entityType, string $entityId, ?array $old = null, ?array $new = null): void
    {
        if (Tenant::organizationId() === null || Tenant::siteId() === null) {
            return;
        }
        Audit::record($action, $entityType, $entityId, $old, $new);
    }
}
