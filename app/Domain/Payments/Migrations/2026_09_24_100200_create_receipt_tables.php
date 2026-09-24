<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Receipts are immutable snapshots: the render-ready payload is frozen at issue time (VAT status, prices, names as they
 * were), so later catalog / tax edits never rewrite a printed receipt. Reprints are counted in receipt_reprint rows
 * (append-only) rather than by mutating the receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        // Atomic per-site per-day counter: INSERT .. ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1).
        DB::unprepared("CREATE TABLE receipt_counter (
  site_id     BINARY(16) NOT NULL,
  business_date DATE NOT NULL,
  seq_value   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (site_id, business_date),
  CONSTRAINT fk_rc_site FOREIGN KEY (site_id) REFERENCES site (id)
) $t");

        DB::unprepared("CREATE TABLE receipt (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  number              VARCHAR(40) NOT NULL,
  organization_id     BINARY(16) NOT NULL,
  site_id             BINARY(16) NOT NULL,
  facility_unit_id    BINARY(16) NOT NULL,
  group_id            BINARY(16) NOT NULL,
  kind                VARCHAR(8) NOT NULL DEFAULT 'SALE' CHECK (kind IN ('SALE')),
  issued_by_staff_id  BINARY(16) NULL,
  device_id           BINARY(16) NULL,
  currency            CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  amount_paid         DECIMAL(19,4) NOT NULL CHECK (amount_paid >= 0),
  payload             JSON NOT NULL,
  issued_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_receipt_number UNIQUE (number),
  CONSTRAINT fk_receipt_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_receipt_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_receipt_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  INDEX ix_receipt_group (group_id),
  INDEX ix_receipt_fac_time (facility_unit_id, issued_at)
) $t");

        DB::unprepared("CREATE TABLE receipt_order (
  receipt_id  BINARY(16) NOT NULL,
  order_id    BINARY(16) NOT NULL,
  PRIMARY KEY (receipt_id, order_id),
  CONSTRAINT fk_ro_receipt FOREIGN KEY (receipt_id) REFERENCES receipt (id),
  CONSTRAINT fk_ro_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  INDEX ix_ro_order (order_id)
) $t");

        DB::unprepared("CREATE TABLE receipt_reprint (
  id          BINARY(16) NOT NULL PRIMARY KEY,
  receipt_id  BINARY(16) NOT NULL,
  staff_id    BINARY(16) NOT NULL,
  device_id   BINARY(16) NULL,
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_rr_receipt FOREIGN KEY (receipt_id) REFERENCES receipt (id),
  CONSTRAINT fk_rr_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  INDEX ix_rr_receipt (receipt_id)
) $t");
    }

    public function down(): void
    {
        foreach (['receipt_reprint', 'receipt_order', 'receipt', 'receipt_counter'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `$t`");
        }
    }
};
