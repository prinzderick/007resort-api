<?php

namespace Tests\Feature;

use App\Support\Ids;
use App\Support\Sync\Outbox;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    public function test_record_writes_in_callers_transaction(): void
    {
        $t = TestData::tenant();
        $entity = Ids::uuid7();
        DB::table('outbox_event')->delete();

        $eventId = null;
        DB::transaction(function () use ($t, $entity, &$eventId) {
            $eventId = Outbox::record('OrderCreated', 'Order', $entity, ['orderId' => $entity, 'total' => '1500.0000'], entityVersion: 3, organizationId: $t['org'], siteId: $t['site']);
        });

        $row = DB::table('outbox_event')->where('id', Ids::toBinary($eventId))->first();
        $this->assertSame('OrderCreated', $row->event_type);
        $this->assertSame('LOCAL', $row->sync_status);
        $this->assertSame(3, (int) $row->entity_version);
        $this->assertSame('1500.0000', json_decode($row->payload, true)['total']);
    }

    public function test_rolls_back_together_with_the_business_change(): void
    {
        $t = TestData::tenant();
        DB::table('outbox_event')->delete();
        try {
            DB::transaction(function () use ($t) {
                Outbox::record('X', 'Y', Ids::uuid7(), [], organizationId: $t['org']);
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('outbox_event')->count());
    }

    public function test_refuses_to_run_outside_a_transaction(): void
    {
        // Test runs inside DatabaseTransactions (level 1); drop to level 0 to prove the guard.
        $level = DB::transactionLevel();
        for ($i = 0; $i < $level; $i++) {
            DB::rollBack();
        }
        try {
            $this->expectException(\LogicException::class);
            Outbox::record('X', 'Y', Ids::uuid7(), [], organizationId: Ids::uuid7());
        } finally {
            DB::beginTransaction();
        }
    }
}
