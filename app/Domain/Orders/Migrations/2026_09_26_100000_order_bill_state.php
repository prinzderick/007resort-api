<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bill (pre-bill) state on the order (docs/WAITER_COLLECTION.md). The order `status` is deliberately NOT extended: a printed bill is
 * additive fields (`bill_printed_at` set = frozen / awaiting payment), so no existing client or state machine has to learn a new status.
 * Plus the permission codes of the bill workflow and their role bundles (architecture/06; bundles are data, not code).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NEW_PERMISSIONS = [
        'bill.print' => 'Print or reprint a pre-bill (freezes the order)',
        'bill.cancel.execute' => 'Request or execute cancelling a printed bill (reopens the order)',
        'bill.cancel.approve' => 'Approve cancelling a printed bill',
    ];

    /** @var array<string, array<string, int>> role code => [permission => requires_approval] */
    private const BUNDLES = [
        'WAIT_STAFF' => ['bill.print' => 0, 'bill.cancel.execute' => 1],
        'BARTENDER' => ['bill.print' => 0, 'bill.cancel.execute' => 1],
        'CASHIER' => ['bill.print' => 0, 'bill.cancel.execute' => 1],
        'UNIT_SUPERVISOR' => ['bill.print' => 0, 'bill.cancel.execute' => 0, 'bill.cancel.approve' => 0],
        'MANAGER' => ['bill.print' => 0, 'bill.cancel.execute' => 0, 'bill.cancel.approve' => 0],
    ];

    public function up(): void
    {
        DB::unprepared('ALTER TABLE `order`
  ADD COLUMN bill_printed_at        DATETIME(6) NULL AFTER voided_at,
  ADD COLUMN bill_printed_by        BINARY(16) NULL AFTER bill_printed_at,
  ADD COLUMN bill_printed_device_id BINARY(16) NULL AFTER bill_printed_by,
  ADD COLUMN bill_print_count       INT UNSIGNED NOT NULL DEFAULT 0 AFTER bill_printed_device_id,
  ADD COLUMN bill_reopen_count      INT UNSIGNED NOT NULL DEFAULT 0 AFTER bill_print_count,
  ADD CONSTRAINT fk_ord_bill_by FOREIGN KEY (bill_printed_by) REFERENCES staff (id)');

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
