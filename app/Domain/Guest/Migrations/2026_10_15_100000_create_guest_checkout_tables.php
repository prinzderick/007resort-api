<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guest checkout (docs/GUEST_CHECKOUT.md): purchases without an account.
 *
 *  - service_token.scope becomes a comma-set (`public.read`, `public.checkout`, later `customer.social`): the old single-value CHECK is replaced;
 *  - customer_order / membership: customer_id becomes NULLable + contact snapshot columns (booking already has customer_name/phone/email);
 *  - guest_contact: repeat-guest dedupe by normalised email (NEVER a login identity, never joined to `customer`);
 *  - guest_order: one checkout (booking | ticket order | membership) + contact snapshot + consent evidence + claim/erasure state;
 *  - guest_access_token: SHA-256 of the r7o_ tokens that authorise ONE guest order;
 *  - guest_message: outbox for confirmation/ticket email + SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        // ---- service_token.scope: drop whatever single-value CHECK exists, add the comma-set one ----
        $checks = DB::select("SELECT tc.CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS tc WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'service_token' AND tc.CONSTRAINT_TYPE = 'CHECK'");
        foreach ($checks as $c) {
            DB::unprepared("ALTER TABLE service_token DROP CHECK `{$c->n}`");
        }
        DB::unprepared("ALTER TABLE service_token MODIFY scope VARCHAR(128) NOT NULL DEFAULT 'public.read'");
        DB::unprepared("ALTER TABLE service_token ADD CONSTRAINT chk_stok_scope CHECK (scope REGEXP '^(public[.]read|public[.]checkout|customer[.]social)(,(public[.]read|public[.]checkout|customer[.]social))*$')");

        // ---- online ticket orders and memberships may belong to nobody (guest) ----
        DB::unprepared('ALTER TABLE customer_order MODIFY customer_id BINARY(16) NULL,
  ADD COLUMN contact_name  VARCHAR(160) NULL,
  ADD COLUMN contact_email VARCHAR(255) NULL,
  ADD COLUMN contact_phone VARCHAR(32) NULL');
        DB::unprepared('ALTER TABLE membership MODIFY customer_id BINARY(16) NULL,
  ADD COLUMN contact_name  VARCHAR(160) NULL,
  ADD COLUMN contact_email VARCHAR(255) NULL,
  ADD COLUMN contact_phone VARCHAR(32) NULL');

        DB::unprepared("CREATE TABLE guest_contact (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  email             VARCHAR(255) NOT NULL,
  phone             VARCHAR(32) NULL,
  name              VARCHAR(160) NOT NULL,
  marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
  consent_version   VARCHAR(32) NULL,
  consented_at      DATETIME(6) NULL,
  order_count       INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_gcontact_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT uq_gcontact_email UNIQUE (organization_id, email)
) $t");

        DB::unprepared("CREATE TABLE guest_order (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  organization_id       BINARY(16) NOT NULL,
  reference             VARCHAR(16) NOT NULL,
  kind                  VARCHAR(12) NOT NULL CHECK (kind IN ('BOOKING','TICKETS','MEMBERSHIP')),
  booking_id            BINARY(16) NULL,
  order_id              BINARY(16) NULL,
  membership_id         BINARY(16) NULL,
  guest_contact_id      BINARY(16) NULL,
  contact_name          VARCHAR(160) NULL,
  contact_email         VARCHAR(255) NULL,
  contact_phone         VARCHAR(32) NULL,
  consent_version       VARCHAR(32) NOT NULL,
  consented_at          DATETIME(6) NOT NULL,
  marketing_consent     TINYINT(1) NOT NULL DEFAULT 0,
  client_ip_hash        CHAR(64) NULL,
  claimed_customer_id   BINARY(16) NULL,
  claimed_at            DATETIME(6) NULL,
  erased_at             DATETIME(6) NULL,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_gorder_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_gorder_booking FOREIGN KEY (booking_id) REFERENCES booking (id),
  CONSTRAINT fk_gorder_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  CONSTRAINT fk_gorder_membership FOREIGN KEY (membership_id) REFERENCES membership (id),
  CONSTRAINT fk_gorder_contact FOREIGN KEY (guest_contact_id) REFERENCES guest_contact (id),
  CONSTRAINT fk_gorder_claimed FOREIGN KEY (claimed_customer_id) REFERENCES customer (id),
  CONSTRAINT uq_gorder_reference UNIQUE (reference),
  CONSTRAINT uq_gorder_booking UNIQUE (booking_id),
  CONSTRAINT uq_gorder_order UNIQUE (order_id),
  CONSTRAINT uq_gorder_membership UNIQUE (membership_id),
  CONSTRAINT chk_gorder_one_subject CHECK ((booking_id IS NOT NULL) + (order_id IS NOT NULL) + (membership_id IS NOT NULL) = 1),
  INDEX ix_gorder_email (contact_email, created_at),
  INDEX ix_gorder_phone (contact_phone, created_at)
) $t");

        DB::unprepared("CREATE TABLE guest_access_token (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  guest_order_id  BINARY(16) NOT NULL,
  token_hash      CHAR(64) NOT NULL,
  expires_at      DATETIME(6) NOT NULL,
  revoked_at      DATETIME(6) NULL,
  last_used_at    DATETIME(6) NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_gtoken_order FOREIGN KEY (guest_order_id) REFERENCES guest_order (id),
  CONSTRAINT uq_gtoken_hash UNIQUE (token_hash),
  INDEX ix_gtoken_order (guest_order_id, revoked_at)
) $t");

        DB::unprepared("CREATE TABLE guest_message (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  guest_order_id  BINARY(16) NOT NULL,
  channel         VARCHAR(8) NOT NULL CHECK (channel IN ('EMAIL','SMS')),
  template        VARCHAR(16) NOT NULL DEFAULT 'CONFIRMATION' CHECK (template IN ('CONFIRMATION','RESEND')),
  status          VARCHAR(10) NOT NULL DEFAULT 'QUEUED' CHECK (status IN ('QUEUED','SENT','FAILED','CANCELLED')),
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_error      VARCHAR(255) NULL,
  sent_at         DATETIME(6) NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_gmsg_order FOREIGN KEY (guest_order_id) REFERENCES guest_order (id),
  INDEX ix_gmsg_queue (status, next_attempt_at),
  INDEX ix_gmsg_order (guest_order_id, channel, created_at)
) $t");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS guest_message');
        DB::unprepared('DROP TABLE IF EXISTS guest_access_token');
        DB::unprepared('DROP TABLE IF EXISTS guest_order');
        DB::unprepared('DROP TABLE IF EXISTS guest_contact');
        DB::unprepared('ALTER TABLE membership DROP COLUMN contact_name, DROP COLUMN contact_email, DROP COLUMN contact_phone');
        DB::unprepared('ALTER TABLE customer_order DROP COLUMN contact_name, DROP COLUMN contact_email, DROP COLUMN contact_phone');
    }
};
