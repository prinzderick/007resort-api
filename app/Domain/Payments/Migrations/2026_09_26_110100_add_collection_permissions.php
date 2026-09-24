<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permission codes of the waiter-collection workflow + role bundles (docs/WAITER_COLLECTION.md section 8).
 * Authorization stays permission-based (never role names): a custom role NAMED "Manager" without `payment.confirm` cannot confirm.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NEW_PERMISSIONS = [
        'payment.collect' => 'Collect money at the customer table (pending confirmation)',
        'payment.confirm' => 'Confirm or reject a pending waiter collection',
        'cash_handover.create' => 'Declare cash to hand over to the cashier',
        'cash_handover.receive' => 'Receive and count a waiter cash handover',
        'cash_handover.signoff' => 'Sign off a cash handover variance',
        'cash_handover.view' => 'View cash handovers and waiter cash-in-hand',
        'device.manage' => 'Manage payment terminals and device assignments',
    ];

    /** @var array<string, list<string>> */
    private const BUNDLES = [
        'WAIT_STAFF' => ['payment.collect', 'cash_handover.create'],
        'BARTENDER' => ['payment.collect', 'cash_handover.create'],
        'CASHIER' => ['payment.confirm', 'cash_handover.receive', 'cash_handover.view'],
        'UNIT_SUPERVISOR' => ['payment.confirm', 'cash_handover.receive', 'cash_handover.signoff', 'cash_handover.view'],
        // MANAGER must stay a superset of the roles it may assign (Identity's escalation guard), so it holds every collection permission too.
        'MANAGER' => ['payment.collect', 'payment.confirm', 'cash_handover.create', 'cash_handover.receive', 'cash_handover.signoff', 'cash_handover.view', 'device.manage'],
        'ACCOUNTANT' => ['cash_handover.view'],
        'IT_ADMIN' => ['device.manage'],
    ];

    public function up(): void
    {
        foreach (self::NEW_PERMISSIONS as $code => $desc) {
            DB::table('permission')->insertOrIgnore(['code' => $code, 'description' => $desc]);
        }
        foreach (self::BUNDLES as $role => $codes) {
            $roleId = DB::table('role')->where('code', $role)->value('id');
            foreach ($codes as $code) {
                $pid = DB::table('permission')->where('code', $code)->value('id');
                if ($roleId && $pid) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $pid, 'requires_approval' => 0]);
                }
            }
        }
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
