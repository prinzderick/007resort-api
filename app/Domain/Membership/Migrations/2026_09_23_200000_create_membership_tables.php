<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Membership schema (architecture/03 §3.4, architecture/04 §2). Sorts after customer (2026_09_22_110000). */
return new class extends Migration
{
    private const TAIL = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(): void
    {
        DB::unprepared('CREATE TABLE membership_plan (
  id                       BINARY(16) NOT NULL PRIMARY KEY,
  organization_id          BINARY(16) NOT NULL,
  code                     VARCHAR(32) NOT NULL,
  name                     VARCHAR(120) NOT NULL,
  description              VARCHAR(500) NULL,
  price                    DECIMAL(19,4) NOT NULL CHECK (price >= 0),
  currency                 CHAR(3) NOT NULL DEFAULT \'NGN\',
  duration_days            INT UNSIGNED NOT NULL CHECK (duration_days >= 1),
  visit_limit              INT UNSIGNED NULL COMMENT \'NULL = unlimited visits per term\',
  guest_allowance          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'guests a member may bring per visit\',
  member_discount_percent  DECIMAL(5,2) NOT NULL DEFAULT 0 CHECK (member_discount_percent BETWEEN 0 AND 100),
  booking_advance_days     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'extra days of advance-booking window (booking privilege)\',
  booking_privileges       JSON NULL,
  grace_period_days        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  renewal_notice_days      SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  property_wide            TINYINT(1) NOT NULL DEFAULT 0,
  is_active                TINYINT(1) NOT NULL DEFAULT 1,
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_mplan_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT uq_mplan_code UNIQUE (organization_id, code)
) '.self::TAIL);

        // A coverage row grants access at a facility_unit AND its descendants. Property-wide plans have
        // membership_plan.property_wide = 1 and no coverage rows required.
        DB::unprepared('CREATE TABLE plan_coverage (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  plan_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  discount_percent  DECIMAL(5,2) NULL CHECK (discount_percent IS NULL OR discount_percent BETWEEN 0 AND 100),
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pcov_plan FOREIGN KEY (plan_id) REFERENCES membership_plan (id),
  CONSTRAINT fk_pcov_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_pcov UNIQUE (plan_id, facility_unit_id),
  INDEX ix_pcov_facility (facility_unit_id)
) '.self::TAIL);

        // Plan terms are SNAPSHOTTED onto the membership at purchase so later plan edits never change a sold term.
        DB::unprepared('CREATE TABLE membership (
  id                       BINARY(16) NOT NULL PRIMARY KEY,
  organization_id          BINARY(16) NOT NULL,
  site_id                  BINARY(16) NOT NULL,
  number                   VARCHAR(24) NOT NULL,
  plan_id                  BINARY(16) NOT NULL,
  customer_id              BINARY(16) NOT NULL,
  status                   VARCHAR(20) NOT NULL DEFAULT \'PENDING_PAYMENT\'
                             CHECK (status IN (\'PENDING_PAYMENT\',\'ACTIVE\',\'EXPIRED\',\'SUSPENDED\',\'CANCELLED\',\'PENDING_RENEWAL\')),
  valid_from               DATETIME(6) NULL,
  valid_until              DATETIME(6) NULL,
  grace_until              DATETIME(6) NULL,
  visits_used              INT UNSIGNED NOT NULL DEFAULT 0,
  visit_limit              INT UNSIGNED NULL,
  guest_allowance          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  member_discount_percent  DECIMAL(5,2) NOT NULL DEFAULT 0,
  grace_period_days        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  duration_days            INT UNSIGNED NOT NULL,
  price_paid               DECIMAL(19,4) NOT NULL CHECK (price_paid >= 0),
  currency                 CHAR(3) NOT NULL DEFAULT \'NGN\',
  payment_id               BINARY(16) NULL COMMENT \'Payments module payment id once captured\',
  payment_reference        VARCHAR(128) NULL,
  purchase_channel         VARCHAR(16) NOT NULL DEFAULT \'RECEPTION\' CHECK (purchase_channel IN (\'RECEPTION\',\'ONLINE\',\'IMPORT\')),
  sold_by_staff_id         BINARY(16) NULL,
  suspended_reason         VARCHAR(255) NULL,
  cancelled_reason         VARCHAR(255) NULL,
  renewal_count            INT UNSIGNED NOT NULL DEFAULT 0,
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_mem_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_mem_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_mem_plan FOREIGN KEY (plan_id) REFERENCES membership_plan (id),
  CONSTRAINT fk_mem_customer FOREIGN KEY (customer_id) REFERENCES customer (id),
  CONSTRAINT fk_mem_seller FOREIGN KEY (sold_by_staff_id) REFERENCES staff (id),
  CONSTRAINT uq_mem_number UNIQUE (number),
  CONSTRAINT uq_mem_payment UNIQUE (payment_id),
  INDEX ix_mem_status_until (status, valid_until),
  INDEX ix_mem_status_grace (status, grace_until),
  INDEX ix_mem_customer (customer_id)
) '.self::TAIL);

        // Append-only.
        DB::unprepared('CREATE TABLE membership_status_history (
  id             BINARY(16) NOT NULL PRIMARY KEY,
  membership_id  BINARY(16) NOT NULL,
  from_status    VARCHAR(20) NULL,
  to_status      VARCHAR(20) NOT NULL,
  reason         VARCHAR(255) NULL,
  source         VARCHAR(16) NOT NULL DEFAULT \'API\' CHECK (source IN (\'API\',\'SCHEDULER\',\'PAYMENT\',\'SYNC\')),
  actor_staff_id BINARY(16) NULL,
  occurred_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_msh_membership FOREIGN KEY (membership_id) REFERENCES membership (id),
  INDEX ix_msh_membership (membership_id, occurred_at)
) '.self::TAIL);

        // Append-only; one row per visit. (membership_id, client_ref) dedups scanner retries.
        DB::unprepared('CREATE TABLE membership_usage (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  membership_id     BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  used_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  guests            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  term              INT UNSIGNED NOT NULL DEFAULT 0,
  visit_number      INT UNSIGNED NOT NULL,
  discount_percent  DECIMAL(5,2) NOT NULL DEFAULT 0,
  card_id           BINARY(16) NULL,
  device_id         BINARY(16) NULL,
  staff_id          BINARY(16) NULL,
  client_ref        VARCHAR(64) NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_mu_membership FOREIGN KEY (membership_id) REFERENCES membership (id),
  CONSTRAINT fk_mu_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_mu_ref UNIQUE (membership_id, client_ref),
  CONSTRAINT uq_mu_visit UNIQUE (membership_id, term, visit_number),
  INDEX ix_mu_facility_time (facility_unit_id, used_at)
) '.self::TAIL);

        // One QR (signed token) + optional NFC card + member-id row per membership. identifier is normalised
        // (NFC uid upper-case hex without separators; QR = the signed token; MEMBER_ID = membership number).
        DB::unprepared('CREATE TABLE member_card (
  id             BINARY(16) NOT NULL PRIMARY KEY,
  membership_id  BINARY(16) NOT NULL,
  card_type      VARCHAR(12) NOT NULL CHECK (card_type IN (\'QR\',\'NFC\',\'MEMBER_ID\')),
  identifier     VARCHAR(160) NOT NULL,
  status         VARCHAR(12) NOT NULL DEFAULT \'ACTIVE\' CHECK (status IN (\'ACTIVE\',\'REVOKED\',\'LOST\')),
  issued_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at     DATETIME(6) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_mc_membership FOREIGN KEY (membership_id) REFERENCES membership (id),
  CONSTRAINT uq_mc_identifier UNIQUE (card_type, identifier),
  INDEX ix_mc_membership (membership_id)
) '.self::TAIL);
    }

    public function down(): void
    {
        foreach (['member_card', 'membership_usage', 'membership_status_history', 'membership', 'plan_coverage', 'membership_plan'] as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
    }
};
