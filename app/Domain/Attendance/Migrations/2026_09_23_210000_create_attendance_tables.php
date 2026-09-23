<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Attendance schema (architecture/04 §2). Punches are append-only; attendance_day is derived. */
return new class extends Migration
{
    private const TAIL = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(): void
    {
        // A biometric terminal. `device_id` links the generic device row (device_type BIOMETRIC_TERMINAL);
        // `token_hash` = SHA-256 of the X-Device-Token (secret shown once at creation / rotation).
        DB::unprepared('CREATE TABLE attendance_device (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  device_id         BINARY(16) NULL,
  facility_unit_id  BINARY(16) NULL,
  serial_number     VARCHAR(64) NOT NULL,
  name              VARCHAR(120) NOT NULL,
  adapter           VARCHAR(24) NOT NULL DEFAULT \'ZKTECO_ADMS\' CHECK (adapter IN (\'ZKTECO_ADMS\',\'JSON_PUSH\')),
  time_zone         VARCHAR(64) NOT NULL DEFAULT \'Africa/Lagos\' COMMENT \'terminals push naive local time\',
  token_hash        CHAR(64) NOT NULL,
  status            VARCHAR(12) NOT NULL DEFAULT \'ACTIVE\' CHECK (status IN (\'ACTIVE\',\'DISABLED\')),
  last_seen_at      DATETIME(6) NULL,
  last_punch_at     DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ad_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_ad_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_ad_device FOREIGN KEY (device_id) REFERENCES device (id),
  CONSTRAINT fk_ad_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_ad_serial UNIQUE (serial_number),
  CONSTRAINT uq_ad_token UNIQUE (token_hash)
) '.self::TAIL);

        // terminal user id (PIN / enrolled id) on ONE terminal -> staff member.
        DB::unprepared('CREATE TABLE staff_biometric_link (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  staff_id              BINARY(16) NOT NULL,
  attendance_device_id  BINARY(16) NOT NULL,
  terminal_user_id      VARCHAR(32) NOT NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_sbl_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  CONSTRAINT fk_sbl_device FOREIGN KEY (attendance_device_id) REFERENCES attendance_device (id),
  CONSTRAINT uq_sbl_terminal_user UNIQUE (attendance_device_id, terminal_user_id),
  INDEX ix_sbl_staff (staff_id)
) '.self::TAIL);

        // Raw, append-only. Never updated or deleted by the application; staff is resolved at read time via
        // staff_biometric_link so a late-created link retroactively attributes old punches.
        DB::unprepared('CREATE TABLE attendance_punch (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  organization_id       BINARY(16) NOT NULL,
  attendance_device_id  BINARY(16) NOT NULL,
  terminal_user_id      VARCHAR(32) NOT NULL,
  punched_at            DATETIME(6) NOT NULL COMMENT \'UTC\',
  verify_mode           VARCHAR(24) NULL,
  direction             VARCHAR(8) NOT NULL DEFAULT \'UNKNOWN\' CHECK (direction IN (\'IN\',\'OUT\',\'UNKNOWN\')),
  raw_id                VARCHAR(64) NULL,
  received_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ap_device FOREIGN KEY (attendance_device_id) REFERENCES attendance_device (id),
  CONSTRAINT uq_ap_dedup UNIQUE (attendance_device_id, terminal_user_id, punched_at),
  INDEX ix_ap_time (punched_at)
) '.self::TAIL);

        DB::unprepared('CREATE TABLE attendance_day (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  staff_id          BINARY(16) NOT NULL,
  work_date         DATE NOT NULL COMMENT \'site-local calendar date\',
  clock_in          DATETIME(6) NULL,
  clock_out         DATETIME(6) NULL,
  minutes_worked    INT UNSIGNED NULL,
  punch_count       INT UNSIGNED NOT NULL DEFAULT 0,
  source            VARCHAR(20) NOT NULL DEFAULT \'BIOMETRIC\' CHECK (source IN (\'BIOMETRIC\',\'MANUAL_CORRECTION\')),
  status            VARCHAR(14) NOT NULL DEFAULT \'OPEN\' CHECK (status IN (\'OPEN\',\'CLOSED\',\'NEEDS_REVIEW\')),
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_aday_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  CONSTRAINT uq_aday UNIQUE (staff_id, work_date),
  INDEX ix_aday_date (site_id, work_date)
) '.self::TAIL);

        // Clock correction workflow: requester != approver (separation of duties, enforced in the service).
        DB::unprepared('CREATE TABLE attendance_correction (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  organization_id       BINARY(16) NOT NULL,
  site_id               BINARY(16) NOT NULL,
  staff_id              BINARY(16) NOT NULL,
  work_date             DATE NOT NULL,
  requested_clock_in    DATETIME(6) NULL,
  requested_clock_out   DATETIME(6) NULL,
  reason                VARCHAR(255) NOT NULL,
  status                VARCHAR(10) NOT NULL DEFAULT \'PENDING\' CHECK (status IN (\'PENDING\',\'APPROVED\',\'REJECTED\')),
  requested_by          BINARY(16) NOT NULL,
  decided_by            BINARY(16) NULL,
  decided_at            DATETIME(6) NULL,
  decision_note         VARCHAR(255) NULL,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_acor_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  CONSTRAINT fk_acor_req FOREIGN KEY (requested_by) REFERENCES staff (id),
  CONSTRAINT fk_acor_dec FOREIGN KEY (decided_by) REFERENCES staff (id),
  INDEX ix_acor_status (status, created_at)
) '.self::TAIL);
    }

    public function down(): void
    {
        foreach (['attendance_correction', 'attendance_day', 'attendance_punch', 'staff_biometric_link', 'attendance_device'] as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
    }
};
