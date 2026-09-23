<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Devices module beyond V0001: device columns, hashed device token, one-time registration codes, tablet checkout, commands. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE device
  ADD COLUMN hardware_id VARCHAR(128) NULL,
  ADD COLUMN platform VARCHAR(32) NULL,
  ADD COLUMN app_version VARCHAR(32) NULL,
  ADD COLUMN last_seen_at DATETIME(6) NULL,
  ADD COLUMN last_status JSON NULL,
  ADD CONSTRAINT uq_device_hardware UNIQUE (site_id, hardware_id)');

        // Device credential = opaque secret, stored only as SHA-256 (V0001 device_registration.token_id stays as the credential id).
        DB::unprepared('ALTER TABLE device_registration
  ADD COLUMN token_hash CHAR(64) NULL AFTER token_id,
  ADD CONSTRAINT uq_dr_token_hash UNIQUE (token_hash)');

        DB::unprepared('CREATE TABLE device_registration_code (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  code_hash         CHAR(64) NOT NULL,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NULL,
  created_by        BINARY(16) NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at        DATETIME(6) NOT NULL,
  used_at           DATETIME(6) NULL,
  used_by_device_id BINARY(16) NULL,
  CONSTRAINT uq_drc_hash UNIQUE (code_hash),
  CONSTRAINT fk_drc_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_drc_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');

        // active_device_id is NULL once checked in, so UNIQUE(active_device_id) allows many history rows but ONE open checkout per device.
        DB::unprepared("CREATE TABLE tablet_checkout (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  device_id         BINARY(16) NOT NULL,
  staff_id          BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  shift_id          BINARY(16) NULL,
  opening_float     DECIMAL(19,4) NULL CHECK (opening_float IS NULL OR opening_float >= 0),
  checked_out_by    BINARY(16) NOT NULL,
  checked_out_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  checked_in_at     DATETIME(6) NULL,
  checked_in_by     BINARY(16) NULL,
  closing_note      VARCHAR(255) NULL,
  cash_session_id   BINARY(16) NULL,
  status            VARCHAR(12) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','RETURNED','FORCED')),
  active_device_id  BINARY(16) GENERATED ALWAYS AS (CASE WHEN checked_in_at IS NULL THEN device_id END) STORED,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_tc_device FOREIGN KEY (device_id) REFERENCES device (id),
  CONSTRAINT fk_tc_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  CONSTRAINT fk_tc_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_tc_one_active_per_device UNIQUE (active_device_id),
  INDEX ix_tc_staff_active (staff_id, checked_in_at),
  INDEX ix_tc_device_time (device_id, checked_out_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

        DB::unprepared("CREATE TABLE device_command (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  device_id     BINARY(16) NOT NULL,
  command       VARCHAR(16) NOT NULL CHECK (command IN ('FORCE_LOGOUT','REFRESH_STATE','LOCK','REVOKE')),
  payload       JSON NOT NULL,
  issued_by     BINARY(16) NULL,
  issued_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  delivered_at  DATETIME(6) NULL,
  CONSTRAINT fk_dc_device FOREIGN KEY (device_id) REFERENCES device (id),
  INDEX ix_dc_pending (device_id, delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS device_command');
        DB::statement('DROP TABLE IF EXISTS tablet_checkout');
        DB::statement('DROP TABLE IF EXISTS device_registration_code');
        DB::unprepared('ALTER TABLE device_registration DROP INDEX uq_dr_token_hash, DROP COLUMN token_hash');
        DB::unprepared('ALTER TABLE device DROP INDEX uq_device_hardware, DROP COLUMN last_status, DROP COLUMN last_seen_at, DROP COLUMN app_version, DROP COLUMN platform, DROP COLUMN hardware_id');
    }
};
