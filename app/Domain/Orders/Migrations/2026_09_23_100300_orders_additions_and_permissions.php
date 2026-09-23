<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive: client-supplied-id replay fingerprints (contract README "Client-supplied ids"), table occupancy/assignment,
 * and the permission codes the contract needs that V0001 did not seed (+ role bundles, architecture/06).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NEW_PERMISSIONS = [
        'order.view' => 'View orders',
        'order.serve' => 'Mark an order served',
        'order.discount.execute' => 'Apply or request a line discount',
        'order.price_override.execute' => 'Apply or request a manual price override',
        'order.comp.execute' => 'Apply or request a complimentary (comp) line',
        'tab.open' => 'Open a tab and attach orders',
        'table.manage' => 'Change table status, assignment and transfer orders between tables',
        'catalog.availability.manage' => 'Set per-facility product availability (86 an item)',
        'catalog.manage' => 'Create and edit catalog categories and products',
    ];

    /** @var array<string, list<string>> role code => permissions */
    private const BUNDLES = [
        'WAIT_STAFF' => ['order.view', 'order.serve', 'order.void.execute', 'order.discount.execute', 'order.comp.execute', 'tab.open', 'table.manage'],
        'BARTENDER' => ['order.view', 'order.serve', 'order.line.add', 'order.line.remove_unsent', 'order.send', 'order.void.execute', 'order.discount.execute', 'tab.open', 'tab.view_own_facility', 'catalog.availability.manage'],
        'KITCHEN_STAFF' => ['catalog.availability.manage'],
        'CASHIER' => ['order.view', 'order.serve', 'order.discount.execute', 'order.price_override.execute', 'order.comp.execute', 'tab.open', 'table.manage'],
        'UNIT_SUPERVISOR' => ['order.view', 'order.serve', 'order.discount.execute', 'order.price_override.execute', 'order.comp.execute', 'tab.open', 'table.manage', 'catalog.availability.manage', 'prep_ticket.view', 'prep_ticket.transition'],
        'MANAGER' => ['order.view', 'order.serve', 'order.discount.execute', 'order.price_override.execute', 'order.comp.execute', 'tab.open', 'table.manage', 'catalog.availability.manage', 'catalog.manage', 'prep_ticket.view', 'prep_ticket.transition'],
    ];

    public function up(): void
    {
        DB::unprepared('ALTER TABLE `order` ADD COLUMN client_request_hash CHAR(64) NULL AFTER client_created_at');
        DB::unprepared('ALTER TABLE tab ADD COLUMN client_request_hash CHAR(64) NULL AFTER client_created_at');
        DB::unprepared('ALTER TABLE order_line ADD COLUMN client_request_hash CHAR(64) NULL AFTER client_created_at');
        DB::unprepared('ALTER TABLE dining_table
  ADD COLUMN occupied_by_staff_id BINARY(16) NULL AFTER status,
  ADD COLUMN occupied_session_id  BINARY(16) NULL AFTER occupied_by_staff_id,
  ADD COLUMN occupied_at          DATETIME(6) NULL AFTER occupied_session_id,
  ADD COLUMN assigned_staff_id    BINARY(16) NULL AFTER occupied_at,
  ADD CONSTRAINT fk_dt_occupied_by FOREIGN KEY (occupied_by_staff_id) REFERENCES staff (id),
  ADD CONSTRAINT fk_dt_assigned FOREIGN KEY (assigned_staff_id) REFERENCES staff (id)');

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
        // Owner holds every permission.
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
