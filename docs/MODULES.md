# Building a module (read this before writing domain code)

The API is a **modular monolith** (ADR-0002) in Laravel. Every module lives in `app/Domain/<Module>/` and is
**auto-discovered** by `App\Providers\ModuleServiceProvider` — you never edit a shared file to add or extend a module.

## Layout

```
app/Domain/<Module>/
  <Module>ServiceProvider.php   auto-registered (bindings, policies, event listeners, console commands, scheduler, middleware aliases)
  routes.php                    auto-loaded under /api/v1 with the `api` middleware group (no prefix needed in the file)
  Migrations/                   auto-loaded by `php artisan migrate`
  config.php                    merged as config('<module lowercase>.*')   (e.g. Domain/Identity/config.php -> config('identity.x'))
  Http/Controllers, Http/Requests, Http/Resources, Http/Middleware
  Models/  Services/  Events/  Listeners/  Policies/
```

Stub modules already exist (empty providers): Organization, Identity, Devices, Audit, Catalog, Orders, Payments,
Inventory, Hospitality, Booking, Ticketing, Membership, Attendance, Sync, Reporting. To add a new one, just create the directory.

Rules of the road
- A module talks to another module through its **Services / Events**, not by reaching into its tables from controllers.
  (Reading another module's model for a foreign-key lookup is fine; writing to it is not.)
- Business rules live in `Services/` (or domain classes), not in controllers. Controllers validate, authorize, call a service, shape JSON.
- Never edit `database/sql/V0001__initial_schema.sql` or an already-merged migration. Change schema with a NEW migration in your module.

## Migrations

Put them in `app/Domain/<Module>/Migrations/`. Laravel orders **all** migrations by filename across every path, so use a real
timestamp prefix (`php artisan make:migration create_x --path=app/Domain/Orders/Migrations`) and make sure it sorts **after**
the tables you reference. `V0001` (core: organization, site, facility_unit, staff, identity, device, audit, idempotency) is
`2026_09_22_000001`. Use raw SQL (`DB::unprepared`) when you need `CHECK` constraints, `BINARY(16)`, `DATETIME(6)` — that is the house style:

```php
DB::unprepared("CREATE TABLE menu_item (
  id BINARY(16) NOT NULL PRIMARY KEY,
  organization_id BINARY(16) NOT NULL,
  name VARCHAR(200) NOT NULL,
  price DECIMAL(19,4) NOT NULL CHECK (price >= 0),
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','ARCHIVED')),
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_mi_org FOREIGN KEY (organization_id) REFERENCES organization (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
```

Conventions: `BINARY(16)` UUIDv7 ids, `DECIMAL(19,4)` money + currency, `DATETIME(6)` UTC, enums = `VARCHAR` + `CHECK`,
`row_version` on mutable headers, financial rows immutable (reversal rows, never UPDATE/DELETE), soft delete (`deleted_at`) only where
the row must stay referenceable. Add `outbox_event` writes for anything that must reach the other node.

## Models, ids, money (app/Support)

```php
use App\Support\Database\{HasUuidV7, Model};

class MenuItem extends Model {
    use HasUuidV7;                                  // uuid7 primary key, BINARY(16) <-> canonical string
    protected $table = 'menu_item';
    protected array $uuidColumns = ['organization_id'];  // every other BINARY(16) column (FKs)
    protected function casts(): array { return ['price' => \App\Support\Money\MoneyString::class]; }
}
MenuItem::find($canonicalUuid); MenuItem::where('organization_id', $uuid)->get();   // strings just work
```
- `Ids::uuid7()`, `Ids::toBinary($uuid)` / `Ids::fromBinary($bin)` for `DB::table()` queries (Eloquent conversion is automatic).
- Every BINARY(16) column MUST be listed in `$uuidColumns` (or it will fail to JSON-serialise).
- **Money** = decimal string, never float: `Money::of('1500.50')->add(...)->percent('7.5')`; `MoneyString` cast for `DECIMAL(19,4)` columns;
  API JSON: `{"amount":"1500.5000","currency":"NGN"}` or a plain string field. `Money::of(1.5)` throws.
- JSON is camelCase, timestamps ISO-8601 UTC (`...Z`). Write explicit response arrays/`JsonResource`s — don't `return $model` (snake_case).

## Auth, permissions, idempotency on routes

```php
// app/Domain/Orders/routes.php
Route::middleware('auth:staff')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware(['permission:order.create,facility=facilityId', 'idempotent']);   // body/route key `facilityId` scopes the check
    Route::post('orders/{order}/void', ...)->middleware(['permission:order.void.execute', 'idempotent']);   // supervisor approval: see below
});
```
- `auth:staff` = opaque bearer access token (see Identity). Sets `RequestContext` (`staffId`, `organizationId`, `siteId`, `sessionId`, `deviceId`).
- `permission:<code>` is **permission-based only** (role_assignment -> role_permission -> permission; org/site/facility-subtree scope). NEVER
  check a role name/code anywhere. Programmatic check: `app(PermissionChecker::class)->can($staffId, 'order.void.approve', Scope::facility($id))`.
  New permission codes: add a migration that inserts into `permission` (+ `role_permission` bundles).
- `idempotent` = `Idempotency-Key` header required on every non-idempotent mutating request. Effect + stored response commit in ONE transaction;
  replay returns the original status/body (`Idempotent-Replayed: true`); same key with a different body -> 422 `idempotency_key_reused` (missing header -> 400 `idempotency_key_missing`);
  only 2xx responses are stored (any >=400 rolls the request back so the client can retry). Do not use on login.

### Supervisor step-up (approvals)

`POST /auth/staff/step-up` (supervisor PIN/password/NFC+PIN on the operator's device) returns a single-use `X-Step-Up-Token` bound to a permission
(+ optional entity). In a controller: `$approverId = app(StepUpService::class)->consume($request, 'order.void.approve', 'Order', $order->id)`
(null = no header -> create an approval / return 202 per the contract); or use route middleware `stepup:<permission>` (403 `step_up_required` without a valid
token; sets `RequestContext::approverId()`). Record the approver in `Audit::record(..., approvalId:)` / the row you write.

## Errors

Throw `ApiProblem` (RFC 7807, stable `code`; never rename a shipped code):
`throw ApiProblem::conflict('slot_taken', 'That slot was just booked.', ['slotId' => $id]);`
Factories: `badRequest, unauthenticated, forbidden, permissionDenied, notFound, conflict, locked, concurrencyConflict, unprocessable, tooManyRequests`. Validation errors
(`$request->validate()`) automatically become 422 `validation_failed` with `errors: {field: [messages]}`. Unhandled -> 500 `server_error` (no internals leaked).
Codes MUST come from the contract's `ProblemCode` enum where one fits (`007resort-docs/api/openapi/v1.yaml`), e.g. `insufficient_stock`, `slot_unavailable`,
`order_state_invalid`, `capability_disabled`, `concurrency_conflict`; every response carries `correlationId` (echo of `X-Correlation-Id`).
Optimistic concurrency: `Etag::json($body, $model->row_version)` on reads, `Etag::assertMatches($request, $model->row_version)` on updates (428/412 `concurrency_conflict`).

## Pagination

`CursorPage::paginate($query, $request, orderBy: 'id'|'<indexed column>', direction: 'asc'|'desc')` -> `->toArray(fn ($m) => [...])` gives
`{ items: [...], nextCursor: string|null }` (contract envelope). Query params `limit` (max 200) and `cursor`.

## Audit and Outbox — inside the SAME transaction as the change

```php
DB::transaction(function () use ($order, $facilityId) {
    $order->update(['status' => 'VOIDED']);
    Audit::record('order.void', 'Order', $order->id, old: ['status' => 'OPEN'], new: ['status' => 'VOIDED'],
                  facilityUnitId: $facilityId);                       // actor/org/site/device come from RequestContext
    Outbox::record('OrderVoided', 'Order', $order->id, ['orderId' => $order->id, 'total' => '1500.0000'],
                   entityVersion: $order->row_version, facilityId: $facilityId);   // throws if not in a transaction
});
```
- `Audit::record` = append-only, SHA-256 hash-chained (`audit_log`). Values are arrays; money as strings. `Audit::verifyChain()` recomputes the chain.
  Writers are serialised by a row lock on `audit_chain_head` (tail-row locking deadlocks — see ConcurrencyTest).
- `Outbox::record` writes `outbox_event` (event types: `architecture/sync/event-catalogue.md`). The Sync module drains it. To **receive** an event type
  from the other node, register a `SyncApplier` from your provider (`callAfterResolving(SyncApplierRegistry::class, ...)`) — see [sync-engine.md](sync-engine.md).
- Audit every sensitive action (`architecture/06`): voids/discounts/overrides/refunds/adjustments/role & device changes/login.

## Scarce resources and concurrency (stock, slots, tickets, payments, checkouts)

Enforce with **DB constraints first** (UNIQUE / CHECK / conditional `UPDATE ... WHERE remaining >= ?` and check affected rows /
`SELECT ... FOR UPDATE` in a short transaction), never with "read then write in PHP". Keep lock order consistent (parent before child).
Redis locks are only an optimisation. Every scarce-resource operation needs a **real concurrent test**:

```php
// tests/Feature/Booking/SlotRaceTest.php
class SlotRaceTest extends \Tests\ConcurrentTestCase {            // no wrapping transaction; wipes rows in setUp/tearDown
    public function test_only_one_of_eight_wins(): void {
        $slot = /* create committed rows with TestData / models */;
        $results = \Tests\Support\Concurrent::run(8, SlotWorkers::class, 'book', [$slot->id]);   // 8 PHP processes, released together
        $ok = collect($results)->filter(fn ($r) => ($r['result']['status'] ?? null) === 201)->count();
        $this->assertSame(1, $ok);
        $this->assertSame(1, DB::table('booking')->where('slot_id', Ids::toBinary($slot->id))->count());
    }
}
class SlotWorkers {                       // must be autoloadable (tests/Support or PSR-4 in tests/)
    public function book(int $index, string $slotId): array {          // runs in a child process with its own DB connection
        $res = app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create(...));
        return ['status' => $res->getStatusCode()];
    }
}
```
See `tests/Feature/ConcurrencyTest.php` (audit chain, idempotent duplicates). Child processes run `tests/Support/worker.php`, which boots the app
and calls `TestRoutes::register()`; put probe routes there if you need any. Always run against MySQL — never SQLite.

## Tests

`composer test` (PHPUnit, real MySQL `*_test` DB; `tests/TestCase.php` = per-test rolled-back transaction; `TestData` builds tenants, staff, roles, assignments).
Use your OWN database/Redis DB index locally (`DB_DATABASE=r007_<scope>`, tests use `r007_<scope>_test`; set `REDIS_DB`/`REDIS_PREFIX`). Do not drop databases you didn't create.

## Checklist for a module PR

Migration(s) with constraints • models • service with business rules • routes with `auth:staff` + `permission:` (+ `idempotent`) • validation •
`Audit::record`/`Outbox::record` in the transaction • failure states as `ApiProblem` with stable codes • tests incl. a concurrency test for scarce
resources • `composer lint` clean • update `docs/openapi/v1.yaml`.
