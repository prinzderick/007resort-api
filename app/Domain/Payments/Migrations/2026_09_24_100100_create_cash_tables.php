<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer sessions (architecture/07 §5). A cashier opens a session with an opening float, takes cash through it,
 * and closes it by declaring counted cash; the system computes expected cash from the immutable ledger
 * (payments / refunds / reversals / cash_movement) and stores the variance.
 *
 *  - One OPEN session per staff member and one per device: enforced by UNIQUE indexes on generated columns that are
 *    NULL once the session is CLOSED (so history is unlimited but concurrency-safe "double open" is impossible).
 *  - cash_movement is append-only (triggers in 2026_09_24_100400).
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        DB::unprepared("CREATE TABLE cash_session (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  organization_id     BINARY(16) NOT NULL,
  site_id             BINARY(16) NOT NULL,
  facility_unit_id    BINARY(16) NOT NULL,
  device_id           BINARY(16) NULL,
  staff_id            BINARY(16) NOT NULL,
  status              VARCHAR(8) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN','CLOSED')),
  currency            CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  opening_float       DECIMAL(19,4) NOT NULL CHECK (opening_float >= 0),
  expected_cash       DECIMAL(19,4) NULL,
  counted_cash        DECIMAL(19,4) NULL CHECK (counted_cash IS NULL OR counted_cash >= 0),
  variance            DECIMAL(19,4) NULL,
  closing_totals      JSON NULL,
  close_note          VARCHAR(500) NULL,
  closed_by_staff_id  BINARY(16) NULL,
  opened_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  closed_at           DATETIME(6) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  open_staff_key      BINARY(16) GENERATED ALWAYS AS (IF(status = 'OPEN', staff_id, NULL)) STORED,
  open_device_key     BINARY(16) GENERATED ALWAYS AS (IF(status = 'OPEN' AND device_id IS NOT NULL, device_id, NULL)) STORED,
  CONSTRAINT ck_cs_closed CHECK (status = 'OPEN' OR (counted_cash IS NOT NULL AND expected_cash IS NOT NULL AND variance IS NOT NULL AND closed_at IS NOT NULL)),
  CONSTRAINT fk_cs_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_cs_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_cs_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_cs_device FOREIGN KEY (device_id) REFERENCES device (id),
  CONSTRAINT fk_cs_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  CONSTRAINT fk_cs_closed_by FOREIGN KEY (closed_by_staff_id) REFERENCES staff (id),
  CONSTRAINT uq_cs_open_staff UNIQUE (open_staff_key),
  CONSTRAINT uq_cs_open_device UNIQUE (open_device_key),
  INDEX ix_cs_fac_status (facility_unit_id, status, opened_at),
  INDEX ix_cs_staff (staff_id, opened_at)
) $t");

        // Paid-in / paid-out / safe drop against an open session. Immutable.
        DB::unprepared("CREATE TABLE cash_movement (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  cash_session_id  BINARY(16) NOT NULL,
  kind             VARCHAR(10) NOT NULL CHECK (kind IN ('PAID_IN','PAID_OUT','DROP')),
  amount           DECIMAL(19,4) NOT NULL CHECK (amount > 0),
  reason           VARCHAR(255) NOT NULL,
  staff_id         BINARY(16) NOT NULL,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_cm_session FOREIGN KEY (cash_session_id) REFERENCES cash_session (id),
  CONSTRAINT fk_cm_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  INDEX ix_cm_session (cash_session_id)
) $t");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS cash_movement');
        DB::statement('DROP TABLE IF EXISTS cash_session');
    }
};
