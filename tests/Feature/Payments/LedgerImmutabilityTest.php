<?php

namespace Tests\Feature\Payments;

use App\Support\Ids;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsWorld;
use Tests\TestCase;

/** The database itself refuses to rewrite the financial ledger (spec §11), whatever the application code does. */
class LedgerImmutabilityTest extends TestCase
{
    use PaymentsWorld;

    private string $paymentId;

    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->orderId = $this->makeOrder('5000.0000');
        $this->paymentId = $this->postJson('/api/v1/payments', $this->payBody(
            [['orderId' => $this->orderId, 'amount' => '5000.0000']],
            [['tenderType' => 'TRANSFER', 'amount' => '5000.0000', 'reference' => 'IMM-1']],
        ), $this->auth($this->cashierToken))->assertCreated()->json('payments.0.id');
    }

    private function refuses(callable $sql, string $needle = 'R007_LEDGER_IMMUTABLE'): void
    {
        try {
            DB::transaction(fn () => $sql());
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }
        $this->fail('The database accepted a statement that must be rejected.');
    }

    public function test_payment_money_and_identity_columns_cannot_be_edited(): void
    {
        $id = Ids::toBinary($this->paymentId);
        foreach (['amount' => '1.0000', 'tender_type' => 'CASH', 'change_given' => '5.0000', 'taken_by_staff_id' => Ids::toBinary($this->manager->id), 'created_at' => '2000-01-01 00:00:00.000000', 'reference' => 'HACK'] as $col => $val) {
            $this->refuses(fn () => DB::table('payment')->where('id', $id)->update([$col => $val]));
        }
        $this->assertSame('5000.0000', (string) DB::table('payment')->where('id', $id)->value('amount'));
    }

    public function test_payment_rows_cannot_be_deleted_and_allocations_refunds_events_are_append_only(): void
    {
        $id = Ids::toBinary($this->paymentId);
        $this->refuses(fn () => DB::table('payment')->where('id', $id)->delete());
        $this->refuses(fn () => DB::table('payment_allocation')->where('payment_id', $id)->update(['amount' => '1.0000']));
        $this->refuses(fn () => DB::table('payment_allocation')->where('payment_id', $id)->delete());

        $this->postJson("/api/v1/payments/{$this->paymentId}/refund", ['amount' => '100.0000', 'reason' => 'test refund'], $this->auth($this->supervisorToken))->assertCreated();
        $this->refuses(fn () => DB::table('refund')->update(['amount' => '999.0000']));
        $this->refuses(fn () => DB::table('refund')->delete());

        DB::table('provider_event')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'provider' => 'paystack', 'provider_event_id' => 'evt-1', 'event_type' => 'charge.success', 'payload_hash' => str_repeat('a', 64)]);
        $this->refuses(fn () => DB::table('provider_event')->update(['payment_id' => $id]));
        $this->refuses(fn () => DB::table('provider_event')->delete());
        $this->refuses(fn () => DB::table('receipt')->update(['amount_paid' => '1.0000']));
        $this->refuses(fn () => DB::table('receipt')->delete());
    }

    public function test_reversal_rows_are_append_only_and_unique_per_payment(): void
    {
        $this->postJson("/api/v1/payments/{$this->paymentId}/reversal", ['reason' => 'test reversal'], $this->auth($this->supervisorToken))->assertCreated();
        $this->refuses(fn () => DB::table('reversal')->update(['reason' => 'edited']));
        $this->refuses(fn () => DB::table('reversal')->delete());
        $row = (array) DB::table('reversal')->first();
        $row['id'] = Ids::toBinary(Ids::uuid7());
        $this->refuses(fn () => DB::table('reversal')->insert($row), 'uq_rev_payment');
    }

    public function test_payment_status_may_only_follow_the_state_machine(): void
    {
        $id = Ids::toBinary($this->paymentId);
        // CAPTURED -> INITIATED / AUTHORIZING / FAILED are illegal
        foreach (['INITIATED', 'AUTHORIZING', 'FAILED', 'CANCELLED'] as $to) {
            $this->refuses(fn () => DB::table('payment')->where('id', $id)->update(['status' => $to]), 'illegal payment status transition');
        }
        // REFUNDED needs refunded_amount = amount; PARTIALLY_REFUNDED needs 0 < refunded < amount
        $this->refuses(fn () => DB::table('payment')->where('id', $id)->update(['status' => 'REFUNDED', 'refunded_amount' => '10.0000']), 'REFUNDED requires');
        $this->refuses(fn () => DB::table('payment')->where('id', $id)->update(['status' => 'PARTIALLY_REFUNDED', 'refunded_amount' => '5000.0000']), 'PARTIALLY_REFUNDED requires');
        // over-refund is stopped (trigger first; CHECK ck_pay_refund_cap behind it) and the amount can only grow
        $this->refuses(fn () => DB::table('payment')->where('id', $id)->update(['status' => 'PARTIALLY_REFUNDED', 'refunded_amount' => '6000.0000']), 'PARTIALLY_REFUNDED requires');
        DB::table('payment')->where('id', $id)->update(['status' => 'PARTIALLY_REFUNDED', 'refunded_amount' => '100.0000']);
        $this->refuses(fn () => DB::table('payment')->where('id', $id)->update(['refunded_amount' => '50.0000']), 'can only grow');
        DB::table('payment')->where('id', $id)->update(['status' => 'REFUNDED', 'refunded_amount' => '5000.0000']);
        $this->refuses(fn () => DB::table('payment')->where('id', $id)->update(['status' => 'CAPTURED']), 'illegal payment status transition');
    }

    public function test_closed_cash_session_is_frozen_and_sessions_are_never_deleted(): void
    {
        $s = $this->openSession();
        $sid = Ids::toBinary($s);
        $this->refuses(fn () => DB::table('cash_session')->where('id', $sid)->update(['opening_float' => '1.0000']));
        $this->refuses(fn () => DB::table('cash_session')->where('id', $sid)->delete());
        $this->postJson("/api/v1/cash-sessions/{$s}/close", ['countedCash' => '5000.0000'], $this->auth($this->cashierToken))->assertOk();
        $this->refuses(fn () => DB::table('cash_session')->where('id', $sid)->update(['counted_cash' => '9999.0000']), 'frozen');
        $this->refuses(fn () => DB::table('cash_movement')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'cash_session_id' => $sid, 'kind' => 'PAID_IN', 'amount' => '0', 'reason' => 'x', 'staff_id' => Ids::toBinary($this->cashier->id)]), 'cash_movement_chk');
    }

    public function test_application_code_has_no_update_or_delete_path_on_ledger_tables(): void
    {
        // Static guard: the only writers of ledger tables in app code are inserts, plus the payment lifecycle UPDATEs in the services.
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Domain/Payments')));
        foreach ($it as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php' || str_contains($f->getPathname(), '/Migrations/')) {
                continue;
            }
            $src = file_get_contents($f->getPathname());
            foreach (['payment_allocation', 'refund', 'reversal', 'provider_event', 'cash_movement', 'receipt', 'receipt_reprint'] as $table) {
                if (preg_match("/DB::table\\('{$table}'\\)[^;]*->(update|delete|truncate|upsert)\\(/s", $src)) {
                    $offenders[] = $f->getFilename().': '.$table;
                }
            }
            if (preg_match("/DB::table\\('payment'\\)[^;]*->delete\\(/s", $src) || preg_match("/DB::table\\('cash_session'\\)[^;]*->delete\\(/s", $src)) {
                $offenders[] = $f->getFilename().': delete on payment/cash_session';
            }
        }
        $this->assertSame([], $offenders);
    }
}
