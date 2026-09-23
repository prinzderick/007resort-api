<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Support\InboxOutcome;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Cloud side of Cloud -> Local delivery: events are handed out on request and settled by acknowledgement. */
final class PullService
{
    public function __construct(private readonly OutboxDelivery $delivery) {}

    /**
     * Hand out the next events for a peer (marks them SYNCING with a lease; un-acked events are re-served).
     * The cursor is only intra-run pagination (`seq > cursor`); durable progress is the acknowledgement, so an event
     * committed late with a lower seq is still picked up on the next run.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function serve(int $limit, ?string $cursor, ?string $siteId): array
    {
        $after = 0;
        if ($cursor !== null && $cursor !== '') {
            if (! ctype_digit($cursor)) {
                throw new \InvalidArgumentException('invalid cursor');
            }
            $after = (int) $cursor;
        }
        DB::table('outbox_event')->where('sync_status', 'LOCAL')->update(['sync_status' => 'QUEUED']);
        $rows = OutboxQuery::claim($limit + 1, true, $siteId === null ? null : Ids::toBinary($siteId), $after);

        $more = count($rows) > $limit;
        if ($more) { // over-claimed by one to learn hasMore: give the extra back untouched
            $extra = array_pop($rows);
            DB::table('outbox_event')->where('id', $extra->id)->update(['sync_status' => 'QUEUED']);
        }

        return [
            'items' => array_map(OutboxPublisher::envelope(...), $rows),
            'nextCursor' => $more && $rows !== [] ? (string) end($rows)->seq : null,
        ];
    }

    /**
     * @param  list<array{eventId: string, result: string, detail?: ?string}>  $results
     * @return int events settled
     */
    public function acknowledge(array $results): int
    {
        $n = 0;
        foreach ($results as $r) {
            $result = in_array($r['result'], [InboxOutcome::APPLIED, InboxOutcome::DUPLICATE, InboxOutcome::CONFLICT, InboxOutcome::DEFERRED, InboxOutcome::FAILED], true) ? $r['result'] : InboxOutcome::FAILED;
            $this->delivery->record(Ids::toBinary($r['eventId']), $result, $r['detail'] ?? null);
            $n++;
        }

        return $n;
    }
}
