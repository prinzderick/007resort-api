<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payments ledger (architecture/07, 04 §3.3, ADR-0009).
 *
 *  payment             one row per tender. Money columns immutable; only the lifecycle columns
 *                      (status, refunded_amount, unallocated_amount, captured_at, receipt_id, provider_txn_id,
 *                      failure_reason, row_version) may change, and only along the allowed state machine
 *                      (trigger in 2026_09_24_100400).
 *  payment_allocation  which order(s) a payment settles. Insert-only.
 *  refund / reversal   corrections are NEW immutable rows referencing the payment — never edits.
 *  provider_event      webhook inbox; UNIQUE (provider, provider_event_id) = duplicate-callback guard. Insert-only.
 *  settlement(+line)   reconciliation grouping of captured payments per tender/provider/business day.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        DB::unprepared("CREATE TABLE payment (
  id                    BINARY(16) NOT NULL PRIMARY KEY,
  organization_id       BINARY(16) NOT NULL,
  site_id               BINARY(16) NOT NULL,
  facility_unit_id      BINARY(16) NOT NULL,
  group_id              BINARY(16) NOT NULL,
  tender_type           VARCHAR(16) NOT NULL CHECK (tender_type IN ('CASH','CARD','TRANSFER','POS_TERMINAL')),
  provider              VARCHAR(12) NOT NULL DEFAULT 'MANUAL' CHECK (provider IN ('MANUAL','PAYSTACK')),
  provider_reference    VARCHAR(100) NULL,
  provider_txn_id       VARCHAR(64) NULL,
  reference             VARCHAR(128) NULL,
  status                VARCHAR(20) NOT NULL DEFAULT 'INITIATED'
                          CHECK (status IN ('INITIATED','AUTHORIZING','CAPTURED','FAILED','CANCELLED','PARTIALLY_REFUNDED','REFUNDED','REVERSED')),
  amount                DECIMAL(19,4) NOT NULL CHECK (amount > 0),
  tendered              DECIMAL(19,4) NULL,
  change_given          DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (change_given >= 0),
  refunded_amount       DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (refunded_amount >= 0),
  unallocated_amount    DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (unallocated_amount >= 0),
  currency              CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  cash_session_id       BINARY(16) NULL,
  device_id             BINARY(16) NULL,
  taken_by_staff_id     BINARY(16) NULL,
  customer_name         VARCHAR(160) NULL,
  customer_email        VARCHAR(190) NULL,
  subject_type          VARCHAR(12) NULL CHECK (subject_type IN ('BOOKING','MEMBERSHIP')),
  subject_id            BINARY(16) NULL,
  intent                JSON NULL,
  receipt_id            BINARY(16) NULL,
  client_fingerprint    CHAR(64) NULL,
  failure_reason        VARCHAR(255) NULL,
  client_created_at     DATETIME(6) NULL,
  captured_at           DATETIME(6) NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  -- A POS / transfer reference (RRN, bank ref) may back at most ONE live payment (duplicate-posting guard).
  live_ref_key          VARCHAR(160) GENERATED ALWAYS AS (
                          IF(reference IS NOT NULL AND provider = 'MANUAL' AND status NOT IN ('FAILED','CANCELLED','REVERSED'),
                             CONCAT(tender_type, ':', reference), NULL)) STORED,
  CONSTRAINT ck_pay_refund_cap CHECK (refunded_amount <= amount),
  CONSTRAINT ck_pay_change CHECK (tendered IS NULL OR (tendered >= amount AND change_given = tendered - amount)),
  CONSTRAINT ck_pay_captured CHECK (status NOT IN ('CAPTURED','PARTIALLY_REFUNDED','REFUNDED','REVERSED') OR captured_at IS NOT NULL),
  CONSTRAINT fk_pay_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_pay_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_pay_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_pay_session FOREIGN KEY (cash_session_id) REFERENCES cash_session (id),
  CONSTRAINT fk_pay_staff FOREIGN KEY (taken_by_staff_id) REFERENCES staff (id),
  CONSTRAINT fk_pay_receipt FOREIGN KEY (receipt_id) REFERENCES receipt (id),
  CONSTRAINT uq_pay_provider_ref UNIQUE (provider, provider_reference),
  CONSTRAINT uq_pay_live_ref UNIQUE (live_ref_key),
  INDEX ix_pay_group (group_id),
  INDEX ix_pay_fac_time (facility_unit_id, created_at),
  INDEX ix_pay_session (cash_session_id, status),
  INDEX ix_pay_staff (taken_by_staff_id, created_at),
  INDEX ix_pay_subject (subject_type, subject_id)
) $t");

        DB::unprepared("CREATE TABLE payment_allocation (
  id          BINARY(16) NOT NULL PRIMARY KEY,
  payment_id  BINARY(16) NOT NULL,
  order_id    BINARY(16) NOT NULL,
  amount      DECIMAL(19,4) NOT NULL CHECK (amount > 0),
  created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  CONSTRAINT fk_pa_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  CONSTRAINT uq_pa_payment_order UNIQUE (payment_id, order_id),
  INDEX ix_pa_order (order_id)
) $t");

        DB::unprepared("CREATE TABLE refund (
  id                        BINARY(16) NOT NULL PRIMARY KEY,
  organization_id           BINARY(16) NOT NULL,
  site_id                   BINARY(16) NOT NULL,
  payment_id                BINARY(16) NOT NULL,
  amount                    DECIMAL(19,4) NOT NULL CHECK (amount > 0),
  currency                  CHAR(3) NOT NULL DEFAULT 'NGN',
  reason                    VARCHAR(255) NOT NULL,
  tender_type               VARCHAR(16) NOT NULL CHECK (tender_type IN ('CASH','CARD','TRANSFER','POS_TERMINAL')),
  cash_session_id           BINARY(16) NULL,
  approval_id               BINARY(16) NULL,
  requested_by_staff_id     BINARY(16) NOT NULL,
  executed_by_staff_id      BINARY(16) NOT NULL,
  provider_action_required  TINYINT(1) NOT NULL DEFAULT 0,
  status                    VARCHAR(12) NOT NULL DEFAULT 'COMPLETED' CHECK (status IN ('COMPLETED')),
  created_at                DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_rf_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  CONSTRAINT fk_rf_session FOREIGN KEY (cash_session_id) REFERENCES cash_session (id),
  CONSTRAINT fk_rf_approval FOREIGN KEY (approval_id) REFERENCES approval (id),
  CONSTRAINT fk_rf_req FOREIGN KEY (requested_by_staff_id) REFERENCES staff (id),
  CONSTRAINT fk_rf_exec FOREIGN KEY (executed_by_staff_id) REFERENCES staff (id),
  INDEX ix_rf_payment (payment_id),
  INDEX ix_rf_session (cash_session_id)
) $t");

        DB::unprepared("CREATE TABLE reversal (
  id                     BINARY(16) NOT NULL PRIMARY KEY,
  organization_id        BINARY(16) NOT NULL,
  site_id                BINARY(16) NOT NULL,
  payment_id             BINARY(16) NOT NULL,
  amount                 DECIMAL(19,4) NOT NULL CHECK (amount > 0),
  currency               CHAR(3) NOT NULL DEFAULT 'NGN',
  reason                 VARCHAR(255) NOT NULL,
  tender_type            VARCHAR(16) NOT NULL,
  cash_session_id        BINARY(16) NULL,
  approval_id            BINARY(16) NULL,
  requested_by_staff_id  BINARY(16) NOT NULL,
  executed_by_staff_id   BINARY(16) NOT NULL,
  created_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_rev_payment UNIQUE (payment_id),
  CONSTRAINT fk_rev_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  CONSTRAINT fk_rev_session FOREIGN KEY (cash_session_id) REFERENCES cash_session (id),
  CONSTRAINT fk_rev_approval FOREIGN KEY (approval_id) REFERENCES approval (id),
  CONSTRAINT fk_rev_req FOREIGN KEY (requested_by_staff_id) REFERENCES staff (id),
  CONSTRAINT fk_rev_exec FOREIGN KEY (executed_by_staff_id) REFERENCES staff (id),
  INDEX ix_rev_session (cash_session_id)
) $t");

        DB::unprepared("CREATE TABLE provider_event (
  id                 BINARY(16) NOT NULL PRIMARY KEY,
  provider           VARCHAR(32) NOT NULL,
  provider_event_id  VARCHAR(128) NOT NULL,
  event_type         VARCHAR(64) NOT NULL,
  payload_hash       CHAR(64) NOT NULL,
  payload            JSON NULL,
  payment_id         BINARY(16) NULL,
  received_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_provider_event UNIQUE (provider, provider_event_id),
  CONSTRAINT fk_pe_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  INDEX ix_pe_payment (payment_id)
) $t");

        DB::unprepared("CREATE TABLE settlement (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  organization_id     BINARY(16) NOT NULL,
  site_id             BINARY(16) NOT NULL,
  business_date       DATE NOT NULL,
  tender_type         VARCHAR(16) NOT NULL,
  provider            VARCHAR(12) NOT NULL,
  status              VARCHAR(12) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN','RECONCILED')),
  expected_amount     DECIMAL(19,4) NOT NULL DEFAULT 0,
  statement_amount    DECIMAL(19,4) NULL,
  variance            DECIMAL(19,4) NULL,
  statement_reference VARCHAR(128) NULL,
  reconciled_by       BINARY(16) NULL,
  reconciled_at       DATETIME(6) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_settlement_day UNIQUE (site_id, business_date, tender_type, provider),
  CONSTRAINT fk_settle_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_settle_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_settle_by FOREIGN KEY (reconciled_by) REFERENCES staff (id)
) $t");

        DB::unprepared("CREATE TABLE settlement_line (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  settlement_id       BINARY(16) NOT NULL,
  payment_id          BINARY(16) NOT NULL,
  amount              DECIMAL(19,4) NOT NULL,
  statement_reference VARCHAR(128) NULL,
  matched             TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_sl_payment UNIQUE (payment_id),
  CONSTRAINT fk_sl_settlement FOREIGN KEY (settlement_id) REFERENCES settlement (id),
  CONSTRAINT fk_sl_payment FOREIGN KEY (payment_id) REFERENCES payment (id),
  INDEX ix_sl_settlement (settlement_id)
) $t");
    }

    public function down(): void
    {
        foreach (['settlement_line', 'settlement', 'provider_event', 'reversal', 'refund', 'payment_allocation', 'payment'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `$t`");
        }
    }
};
