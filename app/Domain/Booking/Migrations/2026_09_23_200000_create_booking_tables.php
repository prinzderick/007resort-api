<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking engine schema (architecture/10, 04 §3.1, sync/booking-authority-and-offline-allocation).
 *
 * The double-booking guard is `slot_allocation` UNIQUE (resource_id, unit_no, slot_start): every hold/confirmed
 * booking owns one row per (unit x slot) it occupies, and MySQL — not application code — rejects the loser.
 * A resource with `capacity` N has units 1..N ("one row per unit"); whole-resource bookings of a multi-unit
 * resource claim ALL units, so they collide with per-unit bookings by construction (the "combined" mode).
 *
 * `bookable_resource` may ALREADY exist (the Organization module creates the bare table for its demo/admin data: id, org, site,
 * facility, code, name, capacity, is_active ...). Then this migration only ALTERs in the booking columns; otherwise it creates the full
 * table. Either way the final shape is identical.
 *
 * No foreign keys to Catalog/Orders/Ticketing tables (product_id, order_id, ticket_type_id, entitlement_id are soft
 * references): those modules ship independently and must be able to migrate in any order.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $ts = "created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),\n  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)";

        if (Schema::hasTable('bookable_resource')) {
            // Owned by Organization (already migrated): add the booking columns.
            DB::unprepared("ALTER TABLE bookable_resource
  ADD COLUMN product_id                 BINARY(16) NULL,
  ADD COLUMN ticket_type_id             BINARY(16) NULL,
  ADD COLUMN mode                       VARCHAR(24) NOT NULL DEFAULT 'TIME_SLOT' CHECK (mode IN ('WHOLE_RESOURCE','INDIVIDUAL_CAPACITY','TIME_SLOT')),
  ADD COLUMN slot_minutes               INT UNSIGNED NOT NULL DEFAULT 60 CHECK (slot_minutes BETWEEN 5 AND 1440),
  ADD COLUMN max_slots_per_booking      INT UNSIGNED NOT NULL DEFAULT 4 CHECK (max_slots_per_booking >= 1),
  ADD COLUMN price                      DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (price >= 0),
  ADD COLUMN whole_price                DECIMAL(19,4) NULL CHECK (whole_price IS NULL OR whole_price >= 0),
  ADD COLUMN currency                   CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  ADD COLUMN allow_whole_resource       TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN online_bookable            TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN offline_strategy           VARCHAR(32) NOT NULL DEFAULT 'A_OFFLINE_ALLOCATION'
                                          CHECK (offline_strategy IN ('A_OFFLINE_ALLOCATION','B_ONLINE_AUTHORITY_REQUIRED','C_DISABLE_ONLINE')),
  ADD COLUMN local_reserve_units        INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN online_stale_after_seconds INT UNSIGNED NOT NULL DEFAULT 900,
  ADD CONSTRAINT chk_br_reserve CHECK (local_reserve_units <= capacity)");
        } else {
            DB::unprepared("CREATE TABLE bookable_resource (
  id                          BINARY(16) NOT NULL PRIMARY KEY,
  organization_id             BINARY(16) NOT NULL,
  site_id                     BINARY(16) NOT NULL,
  facility_unit_id            BINARY(16) NOT NULL,
  product_id                  BINARY(16) NULL,
  ticket_type_id              BINARY(16) NULL,
  code                        VARCHAR(64) NOT NULL,
  name                        VARCHAR(200) NOT NULL,
  mode                        VARCHAR(24) NOT NULL DEFAULT 'TIME_SLOT'
                                CHECK (mode IN ('WHOLE_RESOURCE','INDIVIDUAL_CAPACITY','TIME_SLOT')),
  capacity                    INT UNSIGNED NOT NULL DEFAULT 1 CHECK (capacity BETWEEN 1 AND 1000),
  slot_minutes                INT UNSIGNED NOT NULL DEFAULT 60 CHECK (slot_minutes BETWEEN 5 AND 1440),
  max_slots_per_booking       INT UNSIGNED NOT NULL DEFAULT 4 CHECK (max_slots_per_booking >= 1),
  price                       DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (price >= 0),
  whole_price                 DECIMAL(19,4) NULL CHECK (whole_price IS NULL OR whole_price >= 0),
  currency                    CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  allow_whole_resource        TINYINT(1) NOT NULL DEFAULT 0,
  online_bookable             TINYINT(1) NOT NULL DEFAULT 1,
  offline_strategy            VARCHAR(32) NOT NULL DEFAULT 'A_OFFLINE_ALLOCATION'
                                CHECK (offline_strategy IN ('A_OFFLINE_ALLOCATION','B_ONLINE_AUTHORITY_REQUIRED','C_DISABLE_ONLINE')),
  local_reserve_units         INT UNSIGNED NOT NULL DEFAULT 0,
  online_stale_after_seconds  INT UNSIGNED NOT NULL DEFAULT 900,
  is_active                   TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at                  DATETIME(6) NULL,
  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_br_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_br_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_br_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_br_code UNIQUE (site_id, code),
  CONSTRAINT chk_br_reserve CHECK (local_reserve_units <= capacity),
  INDEX ix_br_fac (facility_unit_id, is_active)
) $t");
        }

        DB::unprepared("CREATE TABLE booking_rule (
  id                        BINARY(16) NOT NULL PRIMARY KEY,
  organization_id           BINARY(16) NOT NULL,
  resource_id               BINARY(16) NULL,
  facility_unit_id          BINARY(16) NULL,
  hold_ttl_seconds          INT UNSIGNED NULL CHECK (hold_ttl_seconds IS NULL OR hold_ttl_seconds BETWEEN 30 AND 86400),
  min_notice_minutes        INT UNSIGNED NULL,
  max_advance_days          INT UNSIGNED NULL,
  cancel_cutoff_minutes     INT UNSIGNED NULL,
  cancel_fee_percent        DECIMAL(5,2) NULL CHECK (cancel_fee_percent IS NULL OR cancel_fee_percent BETWEEN 0 AND 100),
  reschedule_cutoff_minutes INT UNSIGNED NULL,
  max_reschedules           INT UNSIGNED NULL,
  early_entry_minutes       INT UNSIGNED NULL,
  $ts,
  CONSTRAINT fk_brule_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_brule_res FOREIGN KEY (resource_id) REFERENCES bookable_resource (id),
  CONSTRAINT fk_brule_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_brule_res UNIQUE (resource_id),
  CONSTRAINT uq_brule_fac UNIQUE (facility_unit_id),
  CONSTRAINT chk_brule_scope CHECK ((resource_id IS NULL) <> (facility_unit_id IS NULL))
) $t");

        // Weekly opening windows (local property time, ISO day 1=Mon..7=Sun). No rows => config('booking.default_hours').
        DB::unprepared("CREATE TABLE availability_schedule (
  id           BINARY(16) NOT NULL PRIMARY KEY,
  resource_id  BINARY(16) NOT NULL,
  day_of_week  TINYINT UNSIGNED NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
  open_time    TIME NOT NULL,
  close_time   TIME NOT NULL,
  valid_from   DATE NULL,
  valid_to     DATE NULL,
  $ts,
  CONSTRAINT fk_as_res FOREIGN KEY (resource_id) REFERENCES bookable_resource (id),
  CONSTRAINT chk_as_hours CHECK (close_time > open_time),
  INDEX ix_as_res (resource_id, day_of_week)
) $t");

        DB::unprepared("CREATE TABLE blackout (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  resource_id       BINARY(16) NULL,
  facility_unit_id  BINARY(16) NULL,
  starts_at         DATETIME(6) NOT NULL,
  ends_at           DATETIME(6) NOT NULL,
  reason            VARCHAR(255) NULL,
  created_by        BINARY(16) NULL,
  $ts,
  CONSTRAINT fk_bo_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_bo_res FOREIGN KEY (resource_id) REFERENCES bookable_resource (id),
  CONSTRAINT fk_bo_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT chk_bo_range CHECK (ends_at > starts_at),
  CONSTRAINT chk_bo_scope CHECK (resource_id IS NOT NULL OR facility_unit_id IS NOT NULL),
  INDEX ix_bo_res (resource_id, starts_at),
  INDEX ix_bo_fac (facility_unit_id, starts_at)
) $t");

        DB::unprepared("CREATE TABLE booking_number_counter (
  counter_date  DATE NOT NULL,
  node          CHAR(1) NOT NULL,
  seq_value     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (counter_date, node)
) $t");

        DB::unprepared("CREATE TABLE booking (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  organization_id       BINARY(16) NOT NULL,
  site_id               BINARY(16) NOT NULL,
  facility_unit_id      BINARY(16) NOT NULL,
  resource_id           BINARY(16) NOT NULL,
  number                VARCHAR(40) NOT NULL,
  status                VARCHAR(20) NOT NULL DEFAULT 'HELD'
                          CHECK (status IN ('HELD','PENDING_PAYMENT','CONFIRMED','RESCHEDULED','CANCELLED','EXPIRED','COMPLETED')),
  source                VARCHAR(8) NOT NULL DEFAULT 'STAFF' CHECK (source IN ('STAFF','ONLINE')),
  origin_node           VARCHAR(8) NOT NULL DEFAULT 'LOCAL' CHECK (origin_node IN ('LOCAL','CLOUD')),
  allocation_pool       VARCHAR(8) NOT NULL DEFAULT 'CLOUD' CHECK (allocation_pool IN ('CLOUD','LOCAL')),
  start_at              DATETIME(6) NOT NULL,
  end_at                DATETIME(6) NOT NULL,
  quantity              INT UNSIGNED NOT NULL DEFAULT 1 CHECK (quantity >= 1),
  whole_resource        TINYINT(1) NOT NULL DEFAULT 0,
  customer_id           BINARY(16) NULL,
  customer_name         VARCHAR(160) NULL,
  customer_phone        VARCHAR(32) NULL,
  customer_email        VARCHAR(255) NULL,
  membership_id         BINARY(16) NULL,
  currency              CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  total                 DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (total >= 0),
  amount_paid           DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (amount_paid >= 0),
  hold_expires_at       DATETIME(6) NULL,
  order_id              BINARY(16) NULL,
  entitlement_id        BINARY(16) NULL,
  reschedule_count      INT UNSIGNED NOT NULL DEFAULT 0,
  cancel_reason         VARCHAR(255) NULL,
  cancellation_fee      DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (cancellation_fee >= 0),
  confirmed_at          DATETIME(6) NULL,
  cancelled_at          DATETIME(6) NULL,
  created_by            BINARY(16) NULL,
  device_id             BINARY(16) NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_bk_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_bk_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_bk_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_bk_res FOREIGN KEY (resource_id) REFERENCES bookable_resource (id),
  CONSTRAINT uq_bk_number UNIQUE (number),
  CONSTRAINT chk_bk_range CHECK (end_at > start_at),
  INDEX ix_bk_res_start (resource_id, start_at),
  INDEX ix_bk_status_hold (status, hold_expires_at),
  INDEX ix_bk_order (order_id),
  INDEX ix_bk_updated (updated_at)
) $t");

        DB::unprepared("CREATE TABLE booking_item (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  booking_id    BINARY(16) NOT NULL,
  resource_id   BINARY(16) NOT NULL,
  slot_start    DATETIME(6) NOT NULL,
  slot_end      DATETIME(6) NOT NULL,
  qty           INT UNSIGNED NOT NULL DEFAULT 1 CHECK (qty >= 1),
  whole_resource TINYINT(1) NOT NULL DEFAULT 0,
  unit_price    DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
  line_total    DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (line_total >= 0),
  $ts,
  CONSTRAINT fk_bi_booking FOREIGN KEY (booking_id) REFERENCES booking (id) ON DELETE CASCADE,
  CONSTRAINT fk_bi_resource FOREIGN KEY (resource_id) REFERENCES bookable_resource (id),
  CONSTRAINT chk_bi_range CHECK (slot_end > slot_start),
  INDEX ix_bi_booking (booking_id)
) $t");

        // THE double-booking guard (architecture/04 §3.1). Rows are DELETED when a hold expires / a booking is
        // cancelled or moved, so the unique key never has to reason about status.
        DB::unprepared("CREATE TABLE slot_allocation (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  site_id          BINARY(16) NOT NULL,
  resource_id      BINARY(16) NOT NULL,
  unit_no          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  slot_start       DATETIME(6) NOT NULL,
  slot_end         DATETIME(6) NOT NULL,
  booking_item_id  BINARY(16) NOT NULL,
  pool             VARCHAR(8) NOT NULL DEFAULT 'CLOUD' CHECK (pool IN ('CLOUD','LOCAL')),
  status           VARCHAR(16) NOT NULL DEFAULT 'HELD' CHECK (status IN ('HELD','CONFIRMED','CANCELLED')),
  hold_expires_at  DATETIME(6) NULL,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_sa_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_sa_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_sa_resource FOREIGN KEY (resource_id) REFERENCES bookable_resource (id),
  CONSTRAINT fk_sa_item FOREIGN KEY (booking_item_id) REFERENCES booking_item (id) ON DELETE CASCADE,
  CONSTRAINT uq_slot UNIQUE (resource_id, unit_no, slot_start),
  CONSTRAINT chk_sa_range CHECK (slot_end > slot_start),
  INDEX ix_sa_item (booking_item_id),
  INDEX ix_sa_hold (status, hold_expires_at)
) $t");

        // Permissions (architecture/06). Bundles are additive on top of the seeded catalog; roles are never named in code.
        $perms = [
            'booking.create' => 'Hold and confirm a resource booking',
            'booking.view' => 'View bookings and resource availability',
            'booking.cancel' => 'Cancel a booking per policy',
            'booking.reschedule' => 'Reschedule a booking',
            'booking.configure' => 'Configure bookable resources, rules, blackouts and booking-authority strategy',
        ];
        foreach ($perms as $code => $desc) {
            DB::table('permission')->insertOrIgnore(['code' => $code, 'description' => $desc]);
        }
        $bundle = [
            'CASHIER' => ['booking.create', 'booking.view', 'booking.cancel', 'booking.reschedule'],
            'UNIT_SUPERVISOR' => ['booking.create', 'booking.view', 'booking.cancel', 'booking.reschedule'],
            'MANAGER' => ['booking.create', 'booking.view', 'booking.cancel', 'booking.reschedule', 'booking.configure'],
            'OWNER' => ['booking.create', 'booking.view', 'booking.cancel', 'booking.reschedule', 'booking.configure'],
            'IT_ADMIN' => ['booking.view'],
        ];
        foreach ($bundle as $role => $codes) {
            foreach ($codes as $code) {
                DB::statement('INSERT IGNORE INTO role_permission (role_id, permission_id) SELECT r.id, p.id FROM role r, permission p WHERE r.code = ? AND p.code = ?', [$role, $code]);
            }
        }
    }

    public function down(): void
    {
        foreach (['slot_allocation', 'booking_item', 'booking', 'booking_number_counter', 'blackout', 'availability_schedule', 'booking_rule'] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
        if (DB::table('migrations')->where('migration', '2026_09_22_120000_organization_extensions')->exists()) {
            // Organization owns the table: only remove the columns this migration added.
            DB::unprepared('ALTER TABLE bookable_resource DROP CONSTRAINT chk_br_reserve');
            DB::unprepared('ALTER TABLE bookable_resource DROP COLUMN product_id, DROP COLUMN ticket_type_id, DROP COLUMN mode, DROP COLUMN slot_minutes, DROP COLUMN max_slots_per_booking, DROP COLUMN price, DROP COLUMN whole_price, DROP COLUMN currency, DROP COLUMN allow_whole_resource, DROP COLUMN online_bookable, DROP COLUMN offline_strategy, DROP COLUMN local_reserve_units, DROP COLUMN online_stale_after_seconds');
        } else {
            DB::unprepared('DROP TABLE IF EXISTS bookable_resource');
        }
    }
};
