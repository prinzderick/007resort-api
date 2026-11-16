<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Waiter-only service flow (facility rule `service_flow = WAITER_ONLY`): waiters send, serve and take payment on the tablet.
 *
 *  - order/tab.responsible_staff_id   who currently looks after the order/tab (NULL = the creator). Changed only by an accepted
 *                                      handover or a supervisor reassignment; `created_by` / `opened_by` never change (audit).
 *  - payment.owner_staff_id           whose order was paid when somebody else (a supervisor) took the money.
 *  - tab_handover(+_item)             waiter -> waiter hand-over of open orders/tabs; the receiver must accept.
 *  - print_job                        "send this receipt to the printer" requests, picked up by a POS terminal or the admin portal.
 *  - permissions                      waiterflow.settle (own tabs), waiterflow.manage (all tabs, pay on behalf, reassign), receipt.print.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NEW_PERMISSIONS = [
        'waiterflow.settle' => 'Take payment for your own orders and tabs on the tablet (waiter-only service flow)',
        'waiterflow.manage' => 'See every open tab, take payment on a waiter\'s behalf and reassign tabs (waiter-only service flow)',
        'receipt.print' => 'Send a receipt to a printer (POS terminal or admin portal)',
    ];

    /** @var array<string, list<string>> */
    private const BUNDLES = [
        'WAIT_STAFF' => ['waiterflow.settle', 'receipt.print', 'receipt.view'],
        'BARTENDER' => ['waiterflow.settle', 'receipt.print', 'receipt.view'],
        'CASHIER' => ['waiterflow.settle', 'receipt.print'],
        'UNIT_SUPERVISOR' => ['waiterflow.settle', 'waiterflow.manage', 'receipt.print'],
        'MANAGER' => ['waiterflow.settle', 'waiterflow.manage', 'receipt.print'],
        'ACCOUNTANT' => ['receipt.print'],
    ];

    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        DB::statement('ALTER TABLE `order` ADD COLUMN responsible_staff_id BINARY(16) NULL, ADD INDEX ix_order_resp (responsible_staff_id, status)');
        DB::statement('ALTER TABLE tab ADD COLUMN responsible_staff_id BINARY(16) NULL, ADD INDEX ix_tab_resp (responsible_staff_id, status)');
        DB::statement('ALTER TABLE payment ADD COLUMN owner_staff_id BINARY(16) NULL');

        DB::unprepared("CREATE TABLE tab_handover (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  from_staff_id     BINARY(16) NOT NULL,
  to_staff_id       BINARY(16) NOT NULL,
  status            VARCHAR(10) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','ACCEPTED','REJECTED','CANCELLED','EXPIRED','FORCED')),
  note              VARCHAR(255) NULL,
  decided_by_staff_id BINARY(16) NULL,
  decided_at        DATETIME(6) NULL,
  expires_at        DATETIME(6) NOT NULL,
  device_id         BINARY(16) NULL,
  client_request_hash CHAR(64) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_th_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_th_from FOREIGN KEY (from_staff_id) REFERENCES staff (id),
  CONSTRAINT fk_th_to FOREIGN KEY (to_staff_id) REFERENCES staff (id),
  INDEX ix_th_to (to_staff_id, status),
  INDEX ix_th_from (from_staff_id, status),
  INDEX ix_th_fac (facility_unit_id, status, created_at)
) $t");

        DB::unprepared("CREATE TABLE tab_handover_item (
  handover_id BINARY(16) NOT NULL,
  order_id    BINARY(16) NOT NULL,
  tab_id      BINARY(16) NULL,
  moved       TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (handover_id, order_id),
  CONSTRAINT fk_thi_h FOREIGN KEY (handover_id) REFERENCES tab_handover (id),
  CONSTRAINT fk_thi_o FOREIGN KEY (order_id) REFERENCES `order` (id),
  INDEX ix_thi_order (order_id)
) $t");

        DB::unprepared("CREATE TABLE print_job (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  receipt_id        BINARY(16) NOT NULL,
  copies            TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status            VARCHAR(10) NOT NULL DEFAULT 'QUEUED' CHECK (status IN ('QUEUED','PRINTED','FAILED','CANCELLED')),
  requested_by_staff_id BINARY(16) NOT NULL,
  requested_device_id BINARY(16) NULL,
  printed_by_staff_id BINARY(16) NULL,
  printed_device_id BINARY(16) NULL,
  printed_at        DATETIME(6) NULL,
  failure_reason    VARCHAR(255) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pj_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_pj_receipt FOREIGN KEY (receipt_id) REFERENCES receipt (id),
  INDEX ix_pj_queue (facility_unit_id, status, created_at)
) $t");

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
