# Sync engine (Local <-> Cloud) — developer & operator guide

Implements ADR-0013 and `architecture/sync/*` (007resort-docs). Module: `app/Domain/Sync`. Config: `config/sync.php` (env in `.env.example`).

```
 Local node (APP_NODE=local)                                  Cloud node (APP_NODE=cloud)
 business txn ─┬─ business rows                               ┌────────── POST /sync/inbox ── InboxProcessor ── applier(event_type)
               └─ outbox_event (same txn)  ── OutboxPublisher ┘   (dedup on event_id, one txn, savepoint per applier)
 inbox_event  ◄── Puller ◄────────── GET /sync/pull (+ /pull/ack) ◄── outbox_event (Cloud-originated events)
 SendHeartbeatJob ───────────────── POST /sync/heartbeat ──► site_health  ──► SiteAvailability::isLocalFresh()
```

Local **pushes** its outbox to Cloud and **polls** Cloud for Cloud-originated events. Cloud never opens a connection to Local
(ADR-0005). Local's `/sync/inbox` is closed by default (`SYNC_ACCEPT_PUSH`); Cloud's outbox is served by pull.

## Guarantees (and how)

| Guarantee | Mechanism |
| --- | --- |
| A committed business change is never lost | `Outbox::record()` inside the business transaction; MySQL is the queue, Redis only wakes the publisher. Redis/worker down = delay, not loss |
| At-least-once delivery | Publisher retries with exponential backoff + jitter (`Backoff`); transport/5xx/429/401 are **never** terminal |
| Exactly-once effect | `inbox_event.id` (= `event_id`) PRIMARY KEY; envelope stored + applied in **one** DB transaction; redelivery => `DUPLICATE` (still acked) |
| Poison events do not block | A failing applier is rolled back to a savepoint, the inbox row is kept `FAILED`; the batch continues; after `SYNC_MAX_ATTEMPTS` the outbox row is terminal `FAILED` for an operator |
| Per-entity order | Outbox is drained in `seq` order; an entity's later event is not sent while an earlier one is `FAILED`/backing off. Receiver: `EntityOrdered` appliers defer gaps and apply automatically when the predecessor lands |
| Nothing dropped | Unknown `event_type` => stored + `FAILED` (heals when the applier ships: `r007:sync:replay-failed`). Conflicts are recorded, never overwritten |

Outbox states: `LOCAL -> QUEUED -> SYNCING -> SYNCED | FAILED | CONFLICT` (`retry_count`, `next_retry_at`, `last_error`).
Inbox results: `PENDING` (deferred), `APPLIED`, `CONFLICT`, `FAILED`. Per-event answer to the sender: `APPLIED | DUPLICATE | CONFLICT | DEFERRED | FAILED`.

## Registering an applier (other modules)

One class per event type (or a small class handling several). Register from **your module's service provider**:

```php
use App\Domain\Sync\Contracts\{SyncApplier, EntityOrdered};
use App\Domain\Sync\Services\SyncApplierRegistry;
use App\Domain\Sync\Support\{ApplyResult, InboundEvent};

final class OnlineBookingCreatedApplier implements SyncApplier /*, EntityOrdered */
{
    public function apply(InboundEvent $e): ApplyResult      // already inside the inbox transaction (a savepoint)
    {
        // $e->eventId, $e->eventType, $e->entityId, $e->entityVersion, $e->siteId, $e->sourceNode, $e->payload (array, camelCase)
        Booking::query()->updateOrCreate(['id' => $e->entityId], [...]);   // write domain rows; do NOT commit / call the network
        return ApplyResult::applied();                                     // or ::conflict('BOOKING', ...) / ::deferred('why')
    }
}

// BookingServiceProvider::boot()
$this->callAfterResolving(SyncApplierRegistry::class, function (SyncApplierRegistry $r) {
    $r->register('OnlineBookingCreated', OnlineBookingCreatedApplier::class);          // class-string: resolved lazily
    $r->register(['StockReceived', 'StockAdjusted'], new StockApplier);                 // several types, one applier
    $r->register('HeartbeatAck', fn (InboundEvent $e) => ApplyResult::applied());       // closure
});
```

Rules: throw = `FAILED` (your writes roll back, event kept, retried); return `conflict()`/`deferred()` = your writes roll back too.
Do not write on non-applied paths anyway. The inbox already writes an audit row (`sync.event.applied`, with `sourceNode` + `eventId`)
for every applied event — do not duplicate it. Implement `EntityOrdered` if events of one entity must apply in `entityVersion`
order (the inbox tracks `sync_entity_version`). New event types go in `architecture/sync/event-catalogue.md` in the same PR.

Producing events: `Outbox::record($type, $entityType, $entityId, $payload, entityVersion: $row->row_version)` inside `DB::transaction`.
Set `entityVersion` to the entity's `row_version` **after** the change. Money as decimal strings.

### Two-way editable entities (configuration, staff roster): version-checked apply

Never last-writer-wins. Use `VersionedApply` (or just register a target for the built-in `ConfigurationUpdated` / `StaffRosterUpdated`):

```php
$this->callAfterResolving(VersionedTargets::class, fn (VersionedTargets $t) => $t->register('menuItem',
    new VersionedTarget('menu_item', 'CONFIGURATION', ['name' => ['name', 'string'], 'price' => ['price', 'decimal']])));
// event: type ConfigurationUpdated, entityId = row id, entityVersion = new row_version,
//        payload {"domain":"menuItem","changes":{"name":"...","price":"1500.0000"}}
```

`incoming == local.row_version + 1` => applied; `incoming <= local` => **conflict recorded, not applied** (`CONFIGURATION`/`PERMISSION`,
both payloads kept); gap => deferred until the missing change arrives; row missing => deferred. Built-in targets: `facility` (facility_unit), `staff` (staff, `PERMISSION`).
Only mapped columns can change; unknown keys, or a config event addressing a roster target (and vice versa), are `FAILED`.

## Endpoints

Node-to-node (bearer **node credential**; rate limited per credential; not a staff/device token):

| Endpoint | Node | Purpose |
| --- | --- | --- |
| `POST /api/v1/sync/inbox` `{events:[SyncEvent]}` -> `{results:[{eventId,result,detail?}]}` | Cloud | Receive pushed events (max `SYNC_INBOX_MAX_BATCH`) |
| `GET /api/v1/sync/pull?limit&cursor[&siteId]` -> `{items:[SyncEvent],nextCursor}` | Cloud | Serve Cloud-originated events (leased `SYNC_PULL_LEASE` s until acked) |
| `POST /api/v1/sync/pull/ack` `{results:[{eventId,result,detail?}]}` | Cloud | Settle served events (contract extension) |
| `POST /api/v1/sync/heartbeat` (`nodeId` = site UUID, `sentAt`, `appVersion`, `outboxDepth`, `oldestUnsyncedAt`, `lastPulledCursor`, `lastSyncAt`*, `services`) | Cloud | Upsert `site_health`; \*`lastSyncAt` is a contract extension |

IT/Admin (staff bearer + permission `config.manage`, per the contract; mutations need `Idempotency-Key` and are audited):

`GET /sync/status` · `GET /sync/conflicts[?status&category&entityType]` · `GET /sync/conflicts/{id}` · `POST /sync/conflicts/{id}/resolve`
`{resolution: KEEP_LOCAL|MANUAL|DISMISSED, note}` · `POST /sync/conflicts/{id}/reprocess` · `GET /sync/outbox[?status&eventType]` ·
`POST /sync/outbox/{id}/retry` · `POST /sync/outbox/replay-failed` · `GET /sync/inbox-events[?result]` · `POST /sync/inbox-events/{id}/reprocess`.
`resolve` records the human decision; it never force-applies the incoming event (re-apply the intended change through the normal admin
edit against the current version, per the conflict matrix).

Error codes: `invalid_node_token` 401 (+ `security_event sync.node_auth_failed`), `node_mismatch`/`node_site_mismatch` 403,
`rate_limited` 429, `sync_inbox_disabled`/`sync_pull_disabled`/`sync_heartbeat_disabled` 404, `heartbeat_clock_skew` 422,
`invalid_cursor` 400, `outbox_event_not_retryable`/`conflict_already_resolved` 409.

## Node credentials

```
php artisan r007:sync:token local      # prints PEER_NODE_TOKEN (for the Local node) and the NODE_TOKEN_HASHES entry (for Cloud); shown once
```

Only SHA-256 hashes live on the receiving node (`NODE_TOKEN_HASHES=local:<hex>` or `local@<siteUuid>:<hex>` to bind a credential to one site).
**Rotate** without downtime: generate a new pair -> append the new hash on Cloud (`local:<old>,local:<new>`) -> update `PEER_NODE_TOKEN` on Local
-> remove the old hash. Replay safety = `event_id` dedup (+ heartbeat `sentAt` window `SYNC_HEARTBEAT_MAX_SKEW`). Always use HTTPS in production.

## Heartbeat, health, and the immediate-order gate

Local sends a heartbeat every `SYNC_HEARTBEAT_INTERVAL` s; Cloud upserts `site_health` and a scheduled marker (every 10 s) flips a site `OFFLINE`
only after `SYNC_STALE_AFTER` s of silence (default 90 = 3 missed heartbeats) and broadcasts `site.health` (`private-site.status`).
**Other modules gate immediate-fulfilment online orders with:**

```php
if (! app(\App\Domain\Sync\Services\SiteAvailability::class)->isLocalFresh()) {   // computed from last_heartbeat_at, not from the marker
    throw ApiProblem::conflict('site_offline', 'On-site fulfilment is unavailable right now.');
}
```

(Always `true` when running on the Local node itself.) Do not use it to gate the whole website. `->snapshot()` gives status/age for UIs.

## Operations

```
php artisan r007:sync:status [--json]        # peer reachability, outbox/inbox depth, conflicts, last error
php artisan r007:sync:retry {id} [--inbox]   # re-queue one FAILED outbox event / reprocess one inbox event (audited)
php artisan r007:sync:replay-failed          # re-queue all FAILED outbox events, reprocess all FAILED inbox events (audited)
php artisan r007:sync:run-once               # one synchronous heartbeat + push + pull cycle (debugging)
```

Scheduler (`schedule:work` / `* * * * * schedule:run`): Local — publish (every `SYNC_PUBLISH_INTERVAL`), pull, heartbeat; Cloud — mark-stale (10 s);
both — reprocess deferred inbox (15 s). Jobs are queued on Redis (`SYNC_QUEUE`, default `default`; the standard `queue:work` runs them) and are unique
for 30 s, so a stopped worker cannot pile them up. Redis down: the tick is skipped, events stay in MySQL, sync resumes when Redis returns.
A publisher crash leaves rows `SYNCING`; the next run (single publisher per DB via MySQL `GET_LOCK`) recovers them.

Runbook: `FAILED` outbox rows = the peer refused the event repeatedly -> read `last_error`, fix the applier/data, `r007:sync:retry` or `replay-failed`.
`CONFLICT` = the peer evaluated it and needs a human (`GET /sync/conflicts` on the node that recorded it). Later events of a `FAILED` entity wait behind it (order).

## Run Local + Cloud on one laptop: `composer two-node`

```
composer two-node            # == php artisan r007:two-node   (Ctrl-C stops both)
```

Creates/migrates `r007_sync_local` and `r007_sync_cloud`, seeds one shared org/site, mints a throwaway node credential **in memory** (never written to disk),
and starts serve + queue worker + scheduler for each node with its own env: Local http://127.0.0.1:8171 (Redis DB 10), Cloud http://127.0.0.1:8172 (Redis DB 11),
`APP_NODE`/`DB_DATABASE`/`PEER_URL`/`PEER_NODE_TOKEN`/`NODE_TOKEN_HASHES` injected per process (real environment beats `.env`). Options: `--local-port --cloud-port --local-db --cloud-db --no-serve`.
Watch: `APP_NODE=local DB_DATABASE=r007_sync_local php artisan r007:sync:status`. Requires a `.env` with `APP_KEY` and MySQL/Redis credentials (`cp .env.example .env && php artisan key:generate`).

## Testing (two nodes in one process)

`tests/TwoNodeTestCase.php` boots a `local` and a `cloud` node in one PHPUnit process: two real MySQL databases
(`<db>` and `SYNC_TEST_CLOUD_DATABASE`, default `<db minus _test>_cloud_test`; created on demand), per-node config, and a real HTTP kernel between them
(`tests/Support/InProcessPeer`: routing, middleware, validation, transactions are real; failure injection: `down`, `failBefore`, `loseResponse`, `respond`).

```php
$this->emit('local', 'PaymentCompleted', 'Payment', $id, [...]);            // outbox row, real transaction, on Local
$this->onNode('local', fn () => app(OutboxPublisher::class)->run());        // pushes to Cloud through the peer
$this->onNode('cloud', fn () => DB::table('inbox_event')->count());
$this->override(['sync.max_attempts' => 3]);                                // config that survives node switches
```

Run: `vendor/bin/phpunit -c phpunit.sync.xml` (own DBs `r007_sync_*_test`, Redis DB 2/3). Scenario D-1, D-4..D-10 are `tests/Feature/Sync/ScenarioD_SyncTest.php`
(D-2/D-3 booking authority, D-11..D-13 ticket/payment webhook belong to their modules). `InboxConcurrencyTest` fires 6 real processes at one event.

## Known gaps / decisions

See the PR description. Highlights: Cloud-side `SiteAvailability` is per site (single-site today); pushed events are trusted to `sourceNode` == credential's node
(site binding is opt-in via `local@<site>:<hash>`); broadcast of `site.health` needs Reverb + channel auth from the realtime module.
