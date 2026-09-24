<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defence in depth for the financial ledger (spec §11: "financial records must never be silently edited").
 * Application code has NO update/delete path on these tables; these triggers make the DATABASE refuse it too, so a
 * buggy query, an ad-hoc SQL session or a future developer cannot rewrite history. (Deployments should additionally
 * REVOKE UPDATE, DELETE on these tables from the application DB user - see docs/PAYMENTS.md.)
 *
 *  insert-only:   payment_allocation, refund, reversal, provider_event, cash_movement, receipt, receipt_reprint, settlement_line*
 *  never-deleted: payment, cash_session
 *  payment:       only lifecycle columns may change, and only along the state machine of architecture/07 §2
 *  cash_session:  OPEN -> CLOSED once; a closed session is frozen
 *
 * (* settlement_line.matched / statement_reference are reconciliation annotations; rows are never deleted.)
 */
return new class extends Migration
{
    private const MSG = 'R007_LEDGER_IMMUTABLE';

    public function up(): void
    {
        foreach (['payment_allocation', 'refund', 'reversal', 'provider_event', 'cash_movement', 'receipt', 'receipt_reprint'] as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_update BEFORE UPDATE ON `{$table}` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": {$table} rows are append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_delete BEFORE DELETE ON `{$table}` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": {$table} rows are append-only (DELETE rejected)'");
        }

        foreach (['payment', 'cash_session', 'settlement_line'] as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_delete BEFORE DELETE ON `{$table}` FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": {$table} rows are never deleted'");
        }

        DB::unprepared("CREATE TRIGGER trg_payment_guard_update BEFORE UPDATE ON payment FOR EACH ROW
BEGIN
  IF NOT (OLD.id <=> NEW.id AND OLD.organization_id <=> NEW.organization_id AND OLD.site_id <=> NEW.site_id
          AND OLD.facility_unit_id <=> NEW.facility_unit_id AND OLD.group_id <=> NEW.group_id
          AND OLD.tender_type <=> NEW.tender_type AND OLD.provider <=> NEW.provider
          AND OLD.provider_reference <=> NEW.provider_reference AND OLD.reference <=> NEW.reference
          AND OLD.amount <=> NEW.amount AND OLD.tendered <=> NEW.tendered AND OLD.change_given <=> NEW.change_given
          AND OLD.currency <=> NEW.currency AND OLD.cash_session_id <=> NEW.cash_session_id
          AND OLD.device_id <=> NEW.device_id AND OLD.taken_by_staff_id <=> NEW.taken_by_staff_id
          AND OLD.customer_name <=> NEW.customer_name AND OLD.customer_email <=> NEW.customer_email
          AND OLD.subject_type <=> NEW.subject_type AND OLD.subject_id <=> NEW.subject_id
          AND OLD.client_fingerprint <=> NEW.client_fingerprint AND OLD.client_created_at <=> NEW.client_created_at
          AND OLD.created_at <=> NEW.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment money/identity columns are immutable';
  END IF;
  IF OLD.captured_at IS NOT NULL AND NOT (OLD.captured_at <=> NEW.captured_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.captured_at is immutable once set';
  END IF;
  IF OLD.receipt_id IS NOT NULL AND NOT (OLD.receipt_id <=> NEW.receipt_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.receipt_id is immutable once set';
  END IF;
  IF OLD.status NOT IN ('INITIATED','AUTHORIZING') AND NOT (OLD.unallocated_amount <=> NEW.unallocated_amount) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.unallocated_amount is fixed at capture';
  END IF;
  IF NEW.refunded_amount < OLD.refunded_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": payment.refunded_amount can only grow';
  END IF;
  IF OLD.status <> NEW.status AND NOT (
       (OLD.status = 'INITIATED' AND NEW.status IN ('AUTHORIZING','CAPTURED','FAILED','CANCELLED'))
    OR (OLD.status = 'AUTHORIZING' AND NEW.status IN ('CAPTURED','FAILED','CANCELLED'))
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

        DB::unprepared("CREATE TRIGGER trg_cash_session_guard_update BEFORE UPDATE ON cash_session FOR EACH ROW
BEGIN
  IF OLD.status = 'CLOSED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": a closed cash session is frozen';
  END IF;
  IF NOT (OLD.id <=> NEW.id AND OLD.organization_id <=> NEW.organization_id AND OLD.site_id <=> NEW.site_id
          AND OLD.facility_unit_id <=> NEW.facility_unit_id AND OLD.device_id <=> NEW.device_id
          AND OLD.staff_id <=> NEW.staff_id AND OLD.opening_float <=> NEW.opening_float
          AND OLD.opened_at <=> NEW.opened_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": cash_session identity/opening columns are immutable';
  END IF;
END");

        // Reconciliation annotations only: the money link is fixed.
        DB::unprepared("CREATE TRIGGER trg_settlement_line_guard_update BEFORE UPDATE ON settlement_line FOR EACH ROW
BEGIN
  IF NOT (OLD.id <=> NEW.id AND OLD.settlement_id <=> NEW.settlement_id AND OLD.payment_id <=> NEW.payment_id AND OLD.amount <=> NEW.amount) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MSG.": settlement_line money link is immutable';
  END IF;
END");
    }

    public function down(): void
    {
        foreach (['payment_allocation', 'refund', 'reversal', 'provider_event', 'cash_movement', 'receipt', 'receipt_reprint'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_delete");
        }
        foreach (['payment', 'cash_session', 'settlement_line'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_delete");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payment_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cash_session_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_settlement_line_guard_update');
    }
};
