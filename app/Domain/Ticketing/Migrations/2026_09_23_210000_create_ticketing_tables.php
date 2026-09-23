<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ticketing / entitlement schema (architecture/11, 04 §3.2).
 *
 * Double redemption is prevented by the CHECKs plus the conditional UPDATE
 *   UPDATE entitlement_item SET qty_redeemed = qty_redeemed + :n WHERE id = :id AND qty_redeemed + :n <= qty
 * (affected rows = 0 => USED). `redemption` and `validation_event` are append-only (no UPDATE/DELETE in code; production
 * DB grants should REVOKE them — architecture/04 §"least privilege").
 *
 * Soft references (no FK): order_id / order_line_id / product_id / booking_id, so Ticketing migrates independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $ts = "created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),\n  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)";
        $modes = "'NONE','SINGLE_USE','MULTIPLE_ENTRY','TIME_LIMITED','ENTRY_EXIT','STAFF_APPROVAL'";

        DB::unprepared("CREATE TABLE ticket_type (
  id                 BINARY(16) NOT NULL PRIMARY KEY,
  organization_id    BINARY(16) NOT NULL,
  site_id            BINARY(16) NOT NULL,
  facility_unit_id   BINARY(16) NOT NULL,
  product_id         BINARY(16) NULL,
  code               VARCHAR(64) NOT NULL,
  name               VARCHAR(200) NOT NULL,
  format             VARCHAR(12) NOT NULL DEFAULT 'INDIVIDUAL' CHECK (format IN ('INDIVIDUAL','COMBINED')),
  validation_mode    VARCHAR(20) NOT NULL DEFAULT 'SINGLE_USE' CHECK (validation_mode IN ($modes)),
  validity_kind      VARCHAR(20) NOT NULL DEFAULT 'ISSUE_DAY' CHECK (validity_kind IN ('ISSUE_DAY','DURATION_MINUTES','BOOKING_SLOT')),
  validity_minutes   INT UNSIGNED NULL,
  early_entry_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_tkt_type_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_tkt_type_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_tkt_type_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_tt_code UNIQUE (site_id, code),
  INDEX ix_tt_product (product_id)
) $t");

        DB::unprepared("CREATE TABLE entitlement (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  qr_token          VARCHAR(80) NOT NULL,
  source_key        VARCHAR(160) NOT NULL,
  status            VARCHAR(12) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','CANCELLED','EXHAUSTED','EXPIRED')),
  booking_id        BINARY(16) NULL,
  order_id          BINARY(16) NULL,
  customer_id       BINARY(16) NULL,
  holder_name       VARCHAR(160) NULL,
  issued_by         BINARY(16) NULL,
  issued_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  cancelled_at      DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_tkt_ent_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_tkt_ent_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT uq_ent_token UNIQUE (qr_token),
  CONSTRAINT uq_ent_source UNIQUE (source_key),
  INDEX ix_ent_booking (booking_id),
  INDEX ix_ent_order (order_id)
) $t");

        DB::unprepared("CREATE TABLE entitlement_item (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  entitlement_id    BINARY(16) NOT NULL,
  kind              VARCHAR(16) NOT NULL CHECK (kind IN ('ACCESS','RENTAL','GOODS','SERVICE')),
  name              VARCHAR(200) NOT NULL,
  facility_unit_id  BINARY(16) NULL,
  ticket_type_id    BINARY(16) NULL,
  product_id        BINARY(16) NULL,
  order_line_id     BINARY(16) NULL,
  validation_mode   VARCHAR(20) NOT NULL DEFAULT 'NONE' CHECK (validation_mode IN ($modes)),
  qty               DECIMAL(14,3) NOT NULL CHECK (qty > 0),
  qty_redeemed      DECIMAL(14,3) NOT NULL DEFAULT 0,
  qty_returned      DECIMAL(14,3) NOT NULL DEFAULT 0,
  qty_inside        DECIMAL(14,3) NOT NULL DEFAULT 0,
  valid_from        DATETIME(6) NULL,
  valid_until       DATETIME(6) NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  $ts,
  CONSTRAINT fk_tkt_item_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlement (id) ON DELETE CASCADE,
  CONSTRAINT chk_tkt_item_redeemed CHECK (qty_redeemed >= 0 AND qty_redeemed <= qty),
  CONSTRAINT chk_tkt_item_returned CHECK (qty_returned >= 0 AND qty_returned <= qty_redeemed),
  CONSTRAINT chk_tkt_item_inside CHECK (qty_inside >= 0 AND qty_inside <= qty_redeemed),
  CONSTRAINT chk_tkt_item_window CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from),
  INDEX ix_ei_ent (entitlement_id),
  INDEX ix_ei_line (order_line_id)
) $t");

        // Append-only. `device_id` is nullable because staff-bearer-only clients (web/admin) may act without a device token.
        DB::unprepared("CREATE TABLE redemption (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  entitlement_item_id   BINARY(16) NOT NULL,
  action                VARCHAR(16) NOT NULL CHECK (action IN ('ENTRY','EXIT','RELEASE','RETURN')),
  qty                   DECIMAL(14,3) NOT NULL CHECK (qty > 0),
  device_id             BINARY(16) NULL,
  staff_id              BINARY(16) NULL,
  facility_unit_id      BINARY(16) NULL,
  item_condition        VARCHAR(8) NULL CHECK (item_condition IS NULL OR item_condition IN ('OK','DAMAGED','LOST')),
  amount                DECIMAL(19,4) NULL,
  note                  VARCHAR(255) NULL,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_tkt_red_item FOREIGN KEY (entitlement_item_id) REFERENCES entitlement_item (id),
  INDEX ix_rd_item (entitlement_item_id, action, created_at)
) $t");

        // Every scan, whatever the outcome (spec §17). Append-only.
        DB::unprepared("CREATE TABLE validation_event (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  entitlement_id        BINARY(16) NOT NULL,
  entitlement_item_id   BINARY(16) NULL,
  action                VARCHAR(8) NOT NULL DEFAULT 'ENTRY' CHECK (action IN ('ENTRY','EXIT')),
  device_id             BINARY(16) NULL,
  staff_id              BINARY(16) NULL,
  facility_unit_id      BINARY(16) NULL,
  result                VARCHAR(20) NOT NULL
                          CHECK (result IN ('VALID','USED','EXPIRED','NOT_YET_VALID','WRONG_FACILITY','CANCELLED','PENDING_APPROVAL')),
  message               VARCHAR(255) NULL,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_tkt_ve_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlement (id),
  INDEX ix_ve_ent (entitlement_id, created_at),
  INDEX ix_ve_device (device_id, created_at)
) $t");

        $perms = [
            'ticket.issue' => 'Issue (or re-issue) an entitlement for an order or booking',
            'ticket.view' => 'View entitlements and look them up by QR token',
            'ticket.redeem' => 'Scan/redeem an entitlement at a facility',
            'ticket.release' => 'Release and take back rental items (Sports Store)',
            'ticket.override' => 'Manual ticket override (staff-approval validation, sensitive)',
        ];
        foreach ($perms as $code => $desc) {
            DB::table('permission')->insertOrIgnore(['code' => $code, 'description' => $desc]);
        }
        $bundle = [
            'CASHIER' => ['ticket.issue', 'ticket.view'],
            'UNIT_SUPERVISOR' => ['ticket.issue', 'ticket.view', 'ticket.redeem', 'ticket.release', 'ticket.override'],
            'MANAGER' => ['ticket.issue', 'ticket.view', 'ticket.redeem', 'ticket.release', 'ticket.override'],
            'OWNER' => ['ticket.issue', 'ticket.view', 'ticket.redeem', 'ticket.release', 'ticket.override'],
            'STOREKEEPER' => ['ticket.view', 'ticket.release'], // Sports Store window: look up a QR, release / take back rentals
            'IT_ADMIN' => ['ticket.view'],
        ];
        foreach ($bundle as $role => $codes) {
            foreach ($codes as $code) {
                DB::statement('INSERT IGNORE INTO role_permission (role_id, permission_id) SELECT r.id, p.id FROM role r, permission p WHERE r.code = ? AND p.code = ?', [$role, $code]);
            }
        }
    }

    public function down(): void
    {
        foreach (['validation_event', 'redemption', 'entitlement_item', 'entitlement', 'ticket_type'] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
    }
};
