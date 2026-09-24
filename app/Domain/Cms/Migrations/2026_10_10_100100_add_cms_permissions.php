<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website CMS permissions and role bundles (docs/CMS_API.md section 5). Authorization stays permission-based (never role names).
 * MARKETING is a data-driven role for the website editor. MANAGER stays a superset of every role it may assign (Identity's escalation guard).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'cms.view' => 'View website CMS content in the admin portal',
        'cms.manage' => 'Create, edit and delete website content, settings and home sections',
        'cms.publish' => 'Publish, unpublish and archive website content',
        'cms.media.manage' => 'Upload, edit and delete website media',
        'cms.subscribers.view' => 'View newsletter subscribers',
        'cms.subscribers.export' => 'Export newsletter subscribers (CSV)',
        'cms.messages.manage' => 'Read and handle website contact messages',
    ];

    /** @var array<string, list<string>> */
    private const BUNDLES = [
        'OWNER' => ['cms.view', 'cms.manage', 'cms.publish', 'cms.media.manage', 'cms.subscribers.view', 'cms.subscribers.export', 'cms.messages.manage'],
        'MANAGER' => ['cms.view', 'cms.manage', 'cms.publish', 'cms.media.manage', 'cms.subscribers.view', 'cms.subscribers.export', 'cms.messages.manage'],
        'IT_ADMIN' => ['cms.view', 'cms.manage', 'cms.media.manage'],
        'MARKETING' => ['cms.view', 'cms.manage', 'cms.publish', 'cms.media.manage', 'cms.subscribers.view', 'cms.subscribers.export', 'cms.messages.manage'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code => $desc) {
            DB::table('permission')->insertOrIgnore(['code' => $code, 'description' => $desc]);
        }
        DB::table('role')->insertOrIgnore(['code' => 'MARKETING', 'name' => 'Marketing / Website editor', 'description' => 'Manages the public website: content, media, subscribers, contact inbox']);
        foreach (self::BUNDLES as $role => $codes) {
            $roleId = DB::table('role')->where('code', $role)->value('id');
            foreach ($codes as $code) {
                $pid = DB::table('permission')->where('code', $code)->value('id');
                if ($roleId && $pid) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $pid, 'requires_approval' => 0]);
                }
            }
        }
    }

    public function down(): void
    {
        //
    }
};
