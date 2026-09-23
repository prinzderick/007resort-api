<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permission codes the Payments contract needs that V0001 did not seed (+ role bundles, architecture/06).
 * `requires_approval = 1` on a cashier's refund/reversal grant is what routes their request into the approval flow
 * (a supervisor holding the matching `*.approve` permission decides it).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NEW_PERMISSIONS = [
        'payment.view' => 'View payments',
        'refund.execute' => 'Request or execute a refund',
        'payment.reversal.execute' => 'Request or execute a same-session payment reversal',
        'cash_session.view' => 'View cash sessions',
        'cash_movement.record' => 'Record a cash paid-in / paid-out / drop against an open cash session',
        'receipt.view' => 'View receipts',
        'cash_session.close_any' => 'Close a cash session opened by another staff member (supervisor)',
    ];

    /** @var array<string, array<string, int>> role code => [permission => requires_approval] */
    private const BUNDLES = [
        'CASHIER' => ['payment.view' => 0, 'cash_session.view' => 0, 'receipt.view' => 0, 'cash_movement.record' => 0, 'refund.execute' => 1, 'payment.reversal.execute' => 1],
        'UNIT_SUPERVISOR' => ['cash_session.close_any' => 0, 'payment.view' => 0, 'cash_session.view' => 0, 'receipt.view' => 0, 'cash_movement.record' => 0, 'refund.execute' => 0, 'payment.reversal.execute' => 0, 'refund.approve' => 0, 'payment.reversal.approve' => 0],
        'MANAGER' => ['cash_session.close_any' => 0, 'payment.view' => 0, 'cash_session.view' => 0, 'receipt.view' => 0, 'cash_movement.record' => 0, 'refund.execute' => 0, 'payment.reversal.execute' => 0, 'refund.approve' => 0, 'payment.reversal.approve' => 0],
        'ACCOUNTANT' => ['payment.view' => 0, 'cash_session.view' => 0, 'receipt.view' => 0],
    ];

    public function up(): void
    {
        foreach (self::NEW_PERMISSIONS as $code => $desc) {
            DB::table('permission')->insertOrIgnore(['code' => $code, 'description' => $desc]);
        }
        foreach (self::BUNDLES as $role => $perms) {
            $roleId = DB::table('role')->where('code', $role)->value('id');
            foreach ($perms as $code => $requiresApproval) {
                $pid = DB::table('permission')->where('code', $code)->value('id');
                if ($roleId && $pid) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $pid, 'requires_approval' => $requiresApproval]);
                }
            }
        }
        // Owner / super admin holds every permission.
        $owner = DB::table('role')->where('code', 'OWNER')->value('id');
        if ($owner) {
            foreach (DB::table('permission')->pluck('id') as $pid) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $owner, 'permission_id' => $pid, 'requires_approval' => 0]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
