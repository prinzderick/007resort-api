<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Waiter collection (docs/WAITER_COLLECTION.md).
 *
 *  payment                       + statuses PENDING_CONFIRMATION / REJECTED / EXPIRED; a pending collection is INSERTED in
 *                                PENDING_CONFIRMATION and may only move to CAPTURED / REJECTED / EXPIRED / CANCELLED (trigger rebuilt);
 *                                cash_session_id may be set once (NULL -> value) at confirmation; live_ref_key ignores REJECTED/EXPIRED.
 *  payment_collection            immutable facts of a collection (who, which device/terminal, slip/approval/bank references, expiry).
 *  payment_collection_decision   at most ONE decision per payment (CONFIRMED | REJECTED | EXPIRED | CANCELLED) - append-only.
 *  cash_in_hand_entry            append-only per-waiter cash ledger (+collected, -handed over).
 *  cash_handover                 declared -> received (variance) -> signed off; guarded transitions.
 *  staff_collection_policy       per-staff cash-holding override (INHERIT | ALLOW | DENY + optional limit). Configuration, audited.
 *  payment_terminal              card terminal registry (configuration, audited).
 */
return new class extends Migration
{
    private const MSG = 'R007_LEDGER_IMMUTABLE';

    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        // ---- payment: new statuses ---------------------------------------------------------------------------------------
        foreach (DB::select("SELECT tc.CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS tc
            JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'payment' AND tc.CONSTRAINT_TYPE = 'CHECK' AND cc.CHECK_CLAUSE LIKE '%INITIATED%'") as $r) {
            DB::statement("ALTER TABLE payment DROP CHECK `{$r->n}`");
        }
        DB::statement("ALTER TABLE payment ADD CONSTRAINT ck_pay_status CHECK (status IN
  ('INITIATED','AUTHORIZING','PENDING_CONFIRMATION','CAPTURED','FAILED','CANCELLED','REJECTED','EXPIRED','PARTIALLY_REFUNDED','REFUNDED','REVERSED'))");

        // The duplicate-posting guard must not keep a REJECTED / EXPIRED slip reference locked forever.
        DB::statement('ALTER TABLE payment DROP INDEX uq_pay_live_ref');
        DB::statement('ALTER TABLE payment DROP COLUMN live_ref_key');
        DB::statement("ALTER TABLE payment ADD COLUMN live_ref_key VARCHAR(160) GENERATED ALWAYS AS (
            IF(reference IS NOT NULL AND provider = 'MANUAL' AND status NOT IN ('FAILED','CANCELLED','REVERSED','REJECTED','EXPIRED'),
               CONCAT(tender_type, ':', reference), NULL)) STORED, ADD CONSTRAINT uq_pay_live_ref UNIQUE (live_ref_key)");

        // ---- payment guard trigger, rebuilt with the collection lifecycle ---------------------------------------------------
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_guard_update');
        DB::unprepared("CREATE TRIGGER trg_payment_guard_update BEFORE UPDATE ON payment FOR EACH ROW
BEGIN
  IF NOT (OLD.id <=> NEW.id AND OLD.organization_id <=> NEW.organization_id AND OLD.site_id <=> NEW.site_id
          AND OLD.facility_unit_id <=> NEW.facility_unit_id AND OLD.group_id <=> NEW.group_id
          AND OLD.tender_type <=> NEW.tender_type AND OLD.provider <=> NEW.provider
          AND OLD.provider_reference <=> NEW.provider_reference AND OLD.reference <=> NEW.reference
          AND OLD.amount <=> NEW.amount AND OLD.tendered <=> NEW.tendered AND OLD.change_given <=> NEW.change_given
          AND OLD.currency <=> NEW.currency
          AND OLD.device_id <=> NEW.device_id AND OLD.taken_by_staff_id <=> NEW.taken_by_staff_id
          AND OLD.customer_name <=> NEW.customer_name AND OLD.customer_email <=> NEW.customer_email
          AND OLD.subject_type <=> NEW.subject_type AND OLD.subject_id <=> NEW.subject_id
          AND OLD.client_fingerprint <=> NEW.client_fingerprint AND OLD.client_created_at <=> NEW.client_created_at
          AND OLD.created_at <=> NEW.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment money/identity columns are immutable';
  END IF;
  IF OLD.cash_session_id IS NOT NULL AND NOT (OLD.cash_session_id <=> NEW.cash_session_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.cash_session_id is immutable once set';
  END IF;
  IF OLD.cash_session_id IS NULL AND NEW.cash_session_id IS NOT NULL AND NOT (OLD.status = 'PENDING_CONFIRMATION' AND NEW.status = 'CAPTURED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.cash_session_id may only be set when a pending collection is confirmed';
  END IF;
  IF OLD.captured_at IS NOT NULL AND NOT (OLD.captured_at <=> NEW.captured_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.captured_at is immutable once set';
  END IF;
  IF OLD.receipt_id IS NOT NULL AND NOT (OLD.receipt_id <=> NEW.receipt_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.receipt_id is immutable once set';
  END IF;
  IF OLD.status NOT IN ('INITIATED','AUTHORIZING','PENDING_CONFIRMATION') AND NOT (OLD.unallocated_amount <=> NEW.unallocated_amount) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.unallocated_amount is fixed at capture';
  END IF;
  IF NEW.refunded_amount < OLD.refunded_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.refunded_amount can only grow';
  END IF;
  IF OLD.status <> NEW.status AND NOT (
       (OLD.status = 'INITIATED' AND NEW.status IN ('AUTHORIZING','CAPTURED','FAILED','CANCELLED'))
    OR (OLD.status = 'AUTHORIZING' AND NEW.status IN ('CAPTURED','FAILED','CANCELLED'))
    OR (OLD.status = 'PENDING_CONFIRMATION' AND NEW.status IN ('CAPTURED','REJECTED','EXPIRED','CANCELLED'))
    OR (OLD.status = 'CAPTURED' AND NEW.status IN ('PARTIALLY_REFUNDED','REFUNDED','REVERSED'))
    OR (OLD.status = 'PARTIALLY_REFUNDED' AND NEW.status = 'REFUNDED')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": illegal payment status transition';
  END IF;
  IF NEW.status = 'REFUNDED' AND NEW.refunded_amount <> NEW.amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": REFUNDED requires refunded_amount = amount';
  END IF;
  IF NEW.status = 'PARTIALLY_REFUNDED' AND (NEW.refunded_amount <= 0 OR NEW.refunded_amount >= NEW.amount) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": PARTIALLY_REFUNDED requires 0 < refunded_amount < amount';
  END IF;
  IF NEW.status IN ('CAPTURED','REVERSED') AND NEW.refunded_amount <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": CAPTURED/REVERSED payments have no refunds';
  END IF;
END");

        // ---- new tables --------------------------------------------------------------------------------------------------
        DB::unprepared("CREATE TABLE payment_terminal (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  organization_id     BINARY(16) NOT NULL,
  site_id             BINARY(16) NOT NULL,
  facility_unit_id    BINARY(16) NOT NULL,
  provider            VARCHAR(20) NOT NULL DEFAULT 'MANUAL_BANK' CHECK (provider IN ('MANUAL_BANK','PAYSTACK_TERMINAL')),
  label               VARCHAR(120) NOT NULL,
  serial              VARCHAR(120) NULL,
  status              VARCHAR(10) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','INACTIVE','RETIRED')),
  assigned_device_id  BINARY(16) NULL,
  assigned_staff_id   BINARY(16) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pterm_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_pterm_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_pterm_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_pterm_staff FOREIGN KEY (assigned_staff_id) REFERENCES staff (id),
  INDEX ix_pterm_fac (facility_unit_id, status)
) $t");

        DB::unprepared("CREATE TABLE payment_collection (
  payment_id              BINARY(16) NOT NULL PRIMARY KEY,
  organization_id         BINARY(16) NOT NULL,
  site_id                 BINARY(16) NOT NULL,
  facility_unit_id        BINARY(16) NOT NULL,
  order_id                BINARY(16) NOT NULL,
  tender                  VARCHAR(14) NOT NULL CHECK (tender IN ('CASH','CARD_TERMINAL','TRANSFER','PAY_LINK')),
  channel                 VARCHAR(10) NOT NULL DEFAULT 'MANUAL' CHECK (channel IN ('MANUAL','PAYSTACK','TERMINAL')),
  collected_by_staff_id   BINARY(16) NOT NULL,
  device_id               BINARY(16) NULL,
  terminal_id             BINARY(16) NULL,
  approval_code           VARCHAR(64) NULL,
  slip_reference          VARCHAR(64) NULL,
  last4                   CHAR(4) NULL,
  bank_reference          VARCHAR(128) NULL,
  note                    VARCHAR(255) NULL,
  client_created_at       DATETIME(6) NULL,
  provider_payload        JSON NULL,
  auto_confirm            TINYINT(1) NOT NULL DEFAULT 0,
  expires_at              DATETIME(6) NOT NULL,
  created_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pcoll_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  CONSTRAINT fk_pcoll_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  CONSTRAINT fk_pcoll_staff FOREIGN KEY (collected_by_staff_id) REFERENCES staff (id),
  CONSTRAINT fk_pcoll_terminal FOREIGN KEY (terminal_id) REFERENCES payment_terminal (id),
  INDEX ix_pcoll_order (order_id),
  INDEX ix_pcoll_staff (collected_by_staff_id, created_at),
  INDEX ix_pcoll_fac_exp (facility_unit_id, expires_at)
) $t");

        DB::unprepared("CREATE TABLE payment_collection_decision (
  id                   BINARY(16) NOT NULL PRIMARY KEY,
  payment_id           BINARY(16) NOT NULL,
  decision             VARCHAR(10) NOT NULL CHECK (decision IN ('CONFIRMED','REJECTED','EXPIRED','CANCELLED')),
  mode                 VARCHAR(10) NOT NULL CHECK (mode IN ('MANUAL','PROVIDER','SYSTEM')),
  decided_by_staff_id  BINARY(16) NULL,
  reason               VARCHAR(255) NULL,
  matched_reference    VARCHAR(128) NULL,
  decided_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_pcdec_payment UNIQUE (payment_id),
  CONSTRAINT fk_pcdec_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  CONSTRAINT fk_pcdec_staff FOREIGN KEY (decided_by_staff_id) REFERENCES staff (id)
) $t");

        DB::unprepared("CREATE TABLE cash_handover (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  organization_id     BINARY(16) NOT NULL,
  site_id             BINARY(16) NOT NULL,
  facility_unit_id    BINARY(16) NOT NULL,
  waiter_staff_id     BINARY(16) NOT NULL,
  device_id           BINARY(16) NULL,
  status              VARCHAR(16) NOT NULL DEFAULT 'PENDING_RECEIPT' CHECK (status IN ('PENDING_RECEIPT','RECEIVED','PENDING_SIGNOFF','SIGNED_OFF')),
  expected_in_hand    DECIMAL(19,4) NOT NULL,
  declared_amount     DECIMAL(19,4) NOT NULL CHECK (declared_amount > 0),
  counted_amount      DECIMAL(19,4) NULL CHECK (counted_amount IS NULL OR counted_amount >= 0),
  variance            DECIMAL(19,4) NULL,
  requires_signoff    TINYINT(1) NOT NULL DEFAULT 0,
  note                VARCHAR(255) NULL,
  receive_note        VARCHAR(255) NULL,
  client_fingerprint  CHAR(64) NULL,
  received_by         BINARY(16) NULL,
  received_at         DATETIME(6) NULL,
  signoff_by          BINARY(16) NULL,
  signoff_at          DATETIME(6) NULL,
  signoff_note        VARCHAR(255) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_chand_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_chand_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_chand_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_chand_waiter FOREIGN KEY (waiter_staff_id) REFERENCES staff (id),
  CONSTRAINT fk_chand_recv FOREIGN KEY (received_by) REFERENCES staff (id),
  CONSTRAINT fk_chand_sign FOREIGN KEY (signoff_by) REFERENCES staff (id),
  INDEX ix_chand_waiter (waiter_staff_id, status),
  INDEX ix_chand_fac (facility_unit_id, status, created_at)
) $t");

        DB::unprepared("CREATE TABLE cash_in_hand_entry (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  staff_id          BINARY(16) NOT NULL,
  kind              VARCHAR(10) NOT NULL CHECK (kind IN ('COLLECTED','HANDOVER')),
  amount            DECIMAL(19,4) NOT NULL,
  payment_id        BINARY(16) NULL,
  handover_id       BINARY(16) NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT ck_cih_sign CHECK ((kind = 'COLLECTED' AND amount > 0 AND payment_id IS NOT NULL) OR (kind = 'HANDOVER' AND amount < 0 AND handover_id IS NOT NULL)),
  CONSTRAINT uq_cihe_payment UNIQUE (payment_id),
  CONSTRAINT uq_cihe_handover UNIQUE (handover_id),
  CONSTRAINT fk_cihe_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  CONSTRAINT fk_cihe_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  CONSTRAINT fk_cihe_handover FOREIGN KEY (handover_id) REFERENCES cash_handover (id),
  INDEX ix_cihe_staff (staff_id, created_at)
) $t");

        DB::unprepared("CREATE TABLE staff_collection_policy (
  staff_id      BINARY(16) NOT NULL PRIMARY KEY,
  cash_holding  VARCHAR(8) NOT NULL DEFAULT 'INHERIT' CHECK (cash_holding IN ('INHERIT','ALLOW','DENY')),
  cash_limit    DECIMAL(19,4) NULL CHECK (cash_limit IS NULL OR cash_limit >= 0),
  updated_by    BINARY(16) NULL,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_scpol_staff FOREIGN KEY (staff_id) REFERENCES staff (id)
) $t");

        // ---- ledger discipline -------------------------------------------------------------------------------------------
        foreach (['payment_collection', 'payment_collection_decision', 'cash_in_hand_entry'] as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_update BEFORE UPDATE ON `{$table}` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": {$table} rows are append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_delete BEFORE DELETE ON `{$table}` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": {$table} rows are append-only (DELETE rejected)'");
        }
        DB::unprepared("CREATE TRIGGER trg_cash_handover_no_delete BEFORE DELETE ON cash_handover FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": cash_handover rows are never deleted'");
        DB::unprepared("CREATE TRIGGER trg_cash_handover_guard_update BEFORE UPDATE ON cash_handover FOR EACH ROW
BEGIN
  IF NOT (OLD.id <=> NEW.id AND OLD.organization_id <=> NEW.organization_id AND OLD.site_id <=> NEW.site_id
          AND OLD.facility_unit_id <=> NEW.facility_unit_id AND OLD.waiter_staff_id <=> NEW.waiter_staff_id
          AND OLD.expected_in_hand <=> NEW.expected_in_hand AND OLD.declared_amount <=> NEW.declared_amount
          AND OLD.client_fingerprint <=> NEW.client_fingerprint AND OLD.created_at <=> NEW.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": cash_handover declared identity/amount columns are immutable';
  END IF;
  IF OLD.status <> NEW.status AND NOT (
       (OLD.status = 'PENDING_RECEIPT' AND NEW.status IN ('RECEIVED','PENDING_SIGNOFF'))
    OR (OLD.status = 'PENDING_SIGNOFF' AND NEW.status = 'SIGNED_OFF')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": illegal cash_handover status transition';
  END IF;
  IF OLD.counted_amount IS NOT NULL AND NOT (OLD.counted_amount <=> NEW.counted_amount AND OLD.variance <=> NEW.variance AND OLD.received_by <=> NEW.received_by) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": cash_handover count is final once received';
  END IF;
END");
    }

    public function down(): void
    {
        foreach (['cash_in_hand_entry', 'cash_handover', 'payment_collection_decision', 'payment_collection', 'staff_collection_policy', 'payment_terminal'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `{$t}`");
        }
    }
};
