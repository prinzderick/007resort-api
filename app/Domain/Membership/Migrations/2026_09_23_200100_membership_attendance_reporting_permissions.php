<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permission codes for Membership / Attendance / Reporting and their default role bundles (architecture/06).
 * Bundles are only defaults: application code checks permissions, never role names.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'membership.view' => 'View memberships and plans',
        'membership.sell' => 'Sell / renew a membership',
        'membership.validate' => 'Validate a membership at a facility entrance/scanner',
        'membership.manage' => 'Suspend, reinstate or cancel a membership',
        'membership.plan.manage' => 'Create and edit membership plans',
        'attendance.view' => 'View staff attendance',
        'attendance.device.manage' => 'Register biometric terminals and staff-to-terminal links',
        'attendance.correction.request' => 'Request an attendance clock correction',
        'report.view' => 'View operational reports for facilities the holder covers',
    ];

    private const BUNDLES = [
        'CASHIER' => ['membership.view', 'membership.sell', 'membership.validate'],
        'WAIT_STAFF' => ['membership.validate'],
        'UNIT_SUPERVISOR' => ['membership.view', 'membership.sell', 'membership.validate', 'membership.manage',
            'attendance.view', 'attendance.correction.request', 'report.view'],
        'ACCOUNTANT' => ['report.view', 'membership.view'],
        'MANAGER' => ['membership.view', 'membership.sell', 'membership.validate', 'membership.manage', 'membership.plan.manage',
            'attendance.view', 'attendance.device.manage', 'attendance.correction.request', 'report.view'],
        'IT_ADMIN' => ['attendance.device.manage'],
        'OWNER' => ['membership.view', 'membership.sell', 'membership.validate', 'membership.manage', 'membership.plan.manage',
            'attendance.view', 'attendance.device.manage', 'attendance.correction.request', 'report.view'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code => $description) {
            DB::statement('INSERT IGNORE INTO permission (code, description) VALUES (?, ?)', [$code, $description]);
        }
        foreach (self::BUNDLES as $role => $codes) {
            foreach ($codes as $code) {
                DB::statement(
                    'INSERT IGNORE INTO role_permission (role_id, permission_id)
                     SELECT r.id, p.id FROM role r, permission p WHERE r.code = ? AND p.code = ?',
                    [$role, $code]
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permission')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permission')->whereIn('id', $ids)->delete();
    }
};
