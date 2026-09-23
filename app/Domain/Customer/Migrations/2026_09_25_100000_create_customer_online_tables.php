<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer online identity (architecture/17, ADR-0013: online customer accounts are Cloud-authoritative).
 *
 * `customer` / `customer_account` come from Identity. This migration adds what the WEBSITE needs and nothing staff-related:
 *   - customer_account: email verification + lockout bookkeeping;
 *   - customer_session: opaque access/refresh token pair (SHA-256 hashes only), rotating refresh with reuse detection.
 *     Deliberately a SEPARATE table from staff `session`: a customer token can never resolve to a staff account and vice versa;
 *   - customer_email_token: single-use email-verification / password-reset secrets (HMAC hashes only, attempt-limited);
 *   - customer_order: which online customer owns which (ticket) order — ownership for /customer/orders/{id} and payments;
 *   - service_token: the read-only `public.read` credential of the website (hashed, rotatable).
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        DB::unprepared('ALTER TABLE customer_account
  ADD COLUMN email_verified_at    DATETIME(6) NULL,
  ADD COLUMN failed_login_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN locked_until         DATETIME(6) NULL,
  ADD COLUMN password_changed_at  DATETIME(6) NULL');

        DB::unprepared("CREATE TABLE customer_session (
  id                      BINARY(16) NOT NULL PRIMARY KEY,
  customer_account_id     BINARY(16) NOT NULL,
  access_token_hash       CHAR(64) NOT NULL,
  refresh_token_hash      CHAR(64) NOT NULL,
  access_expires_at       DATETIME(6) NOT NULL,
  issued_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at              DATETIME(6) NOT NULL,
  revoked_at              DATETIME(6) NULL,
  revoked_reason          VARCHAR(64) NULL,
  replaced_by_session_id  BINARY(16) NULL,
  created_ip              VARCHAR(64) NULL,
  user_agent              VARCHAR(255) NULL,
  created_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_csess_account FOREIGN KEY (customer_account_id) REFERENCES customer_account (id),
  CONSTRAINT fk_csess_replaced FOREIGN KEY (replaced_by_session_id) REFERENCES customer_session (id),
  CONSTRAINT uq_csess_access UNIQUE (access_token_hash),
  CONSTRAINT uq_csess_refresh UNIQUE (refresh_token_hash),
  INDEX ix_csess_account (customer_account_id, revoked_at, expires_at)
) $t");

        DB::unprepared("CREATE TABLE customer_email_token (
  id                   BINARY(16) NOT NULL PRIMARY KEY,
  customer_account_id  BINARY(16) NOT NULL,
  purpose              VARCHAR(8) NOT NULL CHECK (purpose IN ('VERIFY','RESET')),
  code_hash            CHAR(64) NULL,
  token_hash           CHAR(64) NOT NULL,
  attempts             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at           DATETIME(6) NOT NULL,
  consumed_at          DATETIME(6) NULL,
  created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_cet_account FOREIGN KEY (customer_account_id) REFERENCES customer_account (id),
  CONSTRAINT uq_cet_token UNIQUE (token_hash),
  INDEX ix_cet_account (customer_account_id, purpose, consumed_at)
) $t");

        DB::unprepared("CREATE TABLE customer_order (
  order_id          BINARY(16) NOT NULL PRIMARY KEY,
  customer_id       BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  visit_date        DATE NOT NULL,
  adult_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  child_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_corder_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  CONSTRAINT fk_corder_customer FOREIGN KEY (customer_id) REFERENCES customer (id),
  INDEX ix_corder_customer (customer_id, created_at)
) $t");

        DB::unprepared("CREATE TABLE service_token (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  name              VARCHAR(80) NOT NULL,
  token_prefix      VARCHAR(16) NOT NULL,
  token_hash        CHAR(64) NOT NULL,
  scope             VARCHAR(32) NOT NULL DEFAULT 'public.read' CHECK (scope IN ('public.read')),
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  expires_at        DATETIME(6) NULL,
  revoked_at        DATETIME(6) NULL,
  last_used_at      DATETIME(6) NULL,
  rotated_from_id   BINARY(16) NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_stok_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT uq_stok_hash UNIQUE (token_hash),
  INDEX ix_stok_org (organization_id, is_active)
) $t");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS service_token');
        DB::unprepared('DROP TABLE IF EXISTS customer_order');
        DB::unprepared('DROP TABLE IF EXISTS customer_email_token');
        DB::unprepared('DROP TABLE IF EXISTS customer_session');
        DB::unprepared('ALTER TABLE customer_account DROP COLUMN email_verified_at, DROP COLUMN failed_login_count, DROP COLUMN locked_until, DROP COLUMN password_changed_at');
    }
};
