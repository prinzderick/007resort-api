# Booking & Ticketing modules

`app/Domain/Booking` (resources, availability, holds, bookings, Booking Authority policy) and `app/Domain/Ticketing`
(ticket types, entitlements, QR tokens, scans, rentals). Contract: `api/openapi/v1.yaml` (Bookings, Entitlements,
entitlement-tokens). Design: architecture/10, 11, 23 and `sync/booking-authority-and-offline-allocation.md`.

## Invariants (all enforced by MySQL, not by PHP)

| Guarantee | Mechanism |
| --- | --- |
| A slot/unit is never double-booked | `slot_allocation` UNIQUE `(resource_id, unit_no, slot_start)`. A unit is claimed by ONE multi-row INSERT (all its slots or none); a duplicate-key error means "taken" -> next unit -> else `409 slot_unavailable`. Allocation is additionally serialised per resource with a row lock on `bookable_resource` (lock order: resource, booking, allocation rows) so contenders queue instead of deadlocking. |
| A ticket is not redeemed twice | `UPDATE entitlement_item SET qty_redeemed = qty_redeemed + :n WHERE id = :id AND qty_redeemed + :n <= qty` (0 rows => `USED`); CHECK `qty_redeemed <= qty`. SINGLE_USE: `SET qty_redeemed = qty WHERE qty_redeemed = 0`. |
| A rental is not released / returned twice | `... SET qty_redeemed = qty WHERE qty_redeemed = 0` / `SET qty_returned = qty WHERE qty_redeemed = qty AND qty_returned = 0`; CHECK `qty_returned <= qty_redeemed`. |
| Redemptions / scans are never rewritten | `redemption`, `validation_event` are append-only (no UPDATE/DELETE in code; production DB user should be REVOKEd them). |
| Audit + outbox commit with the change | `Audit::record` / `Outbox::record` in the same transaction as every hold-confirm/cancel/reschedule/issue/redeem/release/return. |

MySQL FK / CHECK constraint names are schema-wide: every constraint here is prefixed (`fk_bkslot_*`, `chk_tkt_item_*`, ...). `bookable_resource` is created by the Organization
module when present; this module then only ALTERs in its booking columns (same final shape either way).

Deadlocks / lock-wait timeouts surface as `409 concurrency_conflict` with `meta.retryable=true` (`Booking\Support\Tx`).

## Endpoints (all `/api/v1`, `auth:staff`, mutating = `Idempotency-Key`)

Contract of record:

- `GET /bookings/resources`, `GET /bookings/resources/{id}/availability?from&to`
- `POST /bookings/hold` (`booking.create`) -> `201 HELD` + `holdExpiresAt`; lost race `409 slot_unavailable`
- `GET /bookings`, `GET /bookings/{id}` (`booking.view`, `ETag: "v<rowVersion>"`)
- `POST /bookings/{id}/confirm` (`booking.create`, `If-Match`) -> CONFIRMED + entitlement issued
- `POST /bookings/{id}/cancel` (`booking.cancel`), `POST /bookings/{id}/reschedule` (`booking.reschedule`)
- `POST /entitlements` (`ticket.issue`; `bookingId` or `orderId`, idempotent per source), `GET /entitlements/{id}`
- `GET /entitlement-tokens/{qrToken}` (`ticket.view`), `POST .../redeem` (`ticket.redeem`, ALWAYS HTTP 200 for a known token: `VALID|USED|EXPIRED|WRONG_FACILITY|NOT_YET_VALID|CANCELLED`), `POST .../exit`
- `POST /entitlements/{id}/release`, `POST /entitlements/{id}/return` (`ticket.release`)

Additive (proposed for the contract; clients may ignore):

- `POST /bookings/resources`, `PATCH /bookings/resources/{id}`, `POST /bookings/resources/{id}/blackouts` (`booking.configure`) — incl. `authority` (Booking Authority strategy).
- `POST /bookings/{id}/order {orderId}` — Reception flow: attach the order (slot fee + rentals + store items) that will pay for the hold.
- `GET /entitlements?filter[orderId]|filter[bookingId]` — all QR codes of an order (5 individual pool tickets).
- Hold: `wholeResource` (combined mode). Booking: `wholeResource`, `cancellationFee`. Redeem: `override`, `quantity`. Entitlement item: `subKind`, `quantityReturned`, `quantityInside`. `POST /entitlements` for an order with several tickets returns the first plus `groupEntitlementIds`.

## Modes

Resource `mode`: `WHOLE_RESOURCE` and `TIME_SLOT` = one unit booked one at a time; `INDIVIDUAL_CAPACITY` = `capacity` units, a
booking claims `quantity` units (one allocation row per unit per slot). Combined: `allow_whole_resource` + `wholeResource:true` claims
ALL units, so it collides with per-unit bookings by construction. Slots are a grid anchored at each opening window (property time
`Africa/Lagos`, stored UTC); a booking is a run of contiguous slots (`max_slots_per_booking`).

Validation modes (`entitlement_item.validation_mode`): `NONE` (VALID, nothing consumed), `SINGLE_USE` (first scan consumes the whole
item), `MULTIPLE_ENTRY` (`quantity` per scan up to `qty`), `TIME_LIMITED` (needs a window), `ENTRY_EXIT` (`qty_inside` headcount; `/exit` needs an
open entry), `STAFF_APPROVAL` (409 `approval_required` unless the caller holds `ticket.override` and sends `override:true`).
Facility match: an item bound to a facility is valid at that facility and its descendants (Sports Arena tickets scan at the Sports Entrance).

Tickets: INDIVIDUAL (3 children + 2 adults = 5 entitlements, one ACCESS item each) or COMBINED (1 entitlement, qty n) per `ticket_type.format`.
QR token = `R7.<24 random chars>.<12 HMAC chars>` (unguessable, non-sequential, signature checked before any DB lookup; key `TICKET_QR_KEY` or derived from `APP_KEY`;
share it between Local and Cloud nodes).

## Booking Authority (per-resource strategy A / B / C)

`Booking\Services\BookingAuthorityPolicy` (pure decision) + `bookable_resource.offline_strategy / local_reserve_units / online_stale_after_seconds`.

- `BOOKING_CLOUD_ENABLED=false` (default, single-node MVP): this node is the sole authority for every unit.
- Cloud node: website (`ONLINE`) draws only from the Cloud pool = units `1..capacity-reserve`; strategy C refuses (`capability_disabled`) when the Local heartbeat is older than the threshold; staff/delegated requests may use all units.
- Local node, Cloud reachable + authority bound: DELEGATE (`CloudBookingAuthority::hold`), mirror the reply locally (`BookingSyncApplier::applySnapshot`).
- Local node offline: A = only the Local reserve (units above the Cloud pool; disjoint by construction), B = `409 offline_not_allowed` for THAT resource only, C = as A locally.
- The Sync module implements two interfaces and calls three appliers: `Contracts\ConnectivityProbe`, `Contracts\CloudBookingAuthority`; `BookingSyncApplier::{applySnapshot, applyCancelled, applyRescheduled}` for `OnlineBookingCreated / OnlineBookingCancelled / BookingRescheduled` (idempotent, run inside the inbox transaction).
- Emitted (outbox): `BookingConfirmedLocally` (Local) / `OnlineBookingCreated` (Cloud), `BookingCancelled` / `OnlineBookingCancelled`, `BookingRescheduled`, `EntitlementIssued`, `TicketRedeemed`, `RentalReleased`, `RentalReturned`.

## Payments / Orders integration (Reception flow)

hold slot -> `POST /orders` (slot-fee product = `bookable_resource.product_id`, rentals, store items) -> `POST /bookings/{id}/order` -> `POST /payments` ->
Payments' `PaymentCaptured` (or Orders' `OrderSettled`) fires INSIDE the payment transaction -> `ConfirmBookingsForPaidOrder` confirms the booking and issues ONE QR
(ACCESS + RENTAL + store GOODS items) and `IssueEntitlementsForPaidOrder` issues individual/combined TICKET entitlements for the order. All idempotent; a stale
hold rolls the payment back (`hold_expired`). `PaymentCaptured{subjectType:'BOOKING'}` (Paystack) confirms the booking itself; `BookingPayableSubjectResolver` is bound when Payments' interface exists.
`POST /bookings/{id}/confirm {tenders, cashSessionId}` (contract path) uses `PaymentsBookingGateway` when Orders + Payments exist: the slot fee becomes an order (or the
attached one is paid) and Payments captures the tenders (cash session, receipt, ledger); its `PaymentCaptured` confirms the booking in the same transaction. Requirements at the paying
facility: `payment_timing = PAY_FIRST` (Reception counter) and, for cash, an open cash session. Without Payments the dev-only `UnlinkedPaymentGateway` is used
(`BOOKING_PAYMENT_GATEWAY=auto|unlinked`; production without Payments refuses with 501).

Inventory hooks: `Ticketing\Contracts\RentalStockHook::{rentalOut,rentalIn}` (no-op default, called inside the release/return transaction); `InventoryRentalStockHook` moves pooled
stock through Inventory's `RentalGateway` (RENTAL_OUT / RENTAL_IN via `product_stock_link` at the facility's stock location) when Inventory exists; DAMAGED/LOST returns do not restock.

Sync module (when present): `BookingEventApplier` / `TicketingEventApplier` are registered with `SyncApplierRegistry` for `OnlineBookingCreated`, `BookingConfirmedLocally`,
`OnlineBookingCancelled`, `BookingCancelled`, `BookingRescheduled`, `EntitlementIssued` (full snapshot incl. the QR token), `TicketRedeemed`, `RentalReleased`, `RentalReturned`
(unit collisions => BOOKING conflict, unknown booking => DEFERRED); `SyncConnectivityProbe` answers the policy from `SyncState::peerReachable()` / `SiteAvailability`.
Still to build in Sync: a Local->Cloud synchronous endpoint + client implementing `CloudBookingAuthority::hold` (delegation while online).

## Config / env

`BOOKING_TIMEZONE` (Africa/Lagos), `BOOKING_CLOUD_ENABLED`, `BOOKING_PAYMENT_GATEWAY` (`auto`), `TICKET_QR_KEY`, `TICKET_STORE_FACILITY_CODE` (SPORTS-STORE).
Scheduler: `booking:expire-holds` every minute (also cleared lazily when a hold is what blocks a new request).

## Demo data

Integrated build (Organization/Identity/Devices/Catalog demo framework): `Booking\Demo\BookingDemoSeeder` (priority 110) enriches THEIR facilities/resources (Football Pitch 1, Lawn
Tennis Court 1-2, Basketball Court 1 with hourly prices; Event Hall as per-seat capacity with an offline reserve), adds slot-fee / pool-ticket / rental / store-goods products, pool
ticket types, the Reception `payment_timing=PAY_FIRST` rule and two sample QR entitlements; it adds no staff/roles (sign in as cashier1 at Reception, supervisor1/manager1 at the
Sports Entrance and Pool gate, storekeeper1 at the Sports Store; PIN 1234). Standalone (this module alone) `php artisan r007:demo-seed` (idempotent; refuses in production): facilities RECEPTION / SPORTS-ARENA > SPORTS-ENTRANCE / SPORTS-STORE / POOL, resources Football Pitch,
Lawn Tennis Court 1-2, Basketball Court (hourly, 07:00-21:00), Tennis Clinic (8 seats, whole-clinic allowed, 2 offline-reserve seats), pool ticket types (adult/child), Catalog
products (slot fees, pool tickets, rentals, store goods) when the Catalog tables exist, users `reception` / `entrance` / `store` / `pool` (dev password `Demo-Pass-007!`),
and two sample entitlements whose QR tokens are printed.

## Tests

`tests/Feature/Booking/{BookingFlow,BookingAuthority,ReceptionFlow,Concurrency}Test.php`, `tests/Feature/Ticketing/RedemptionTest.php` — real MySQL. `ConcurrencyTest` fires N
real PHP processes: two simultaneous holds for the last slot -> exactly one 201, the other 409; 8 racing holds on 3 units -> exactly 3 winners on distinct units; racing for an
expired hold -> one new holder, no 5xx; concurrent confirms -> one payment; two scanners on one single-use ticket -> exactly one `VALID`; 8 scanners on a 3-entry pass -> exactly 3
`VALID`; two staff releasing one rental -> exactly one 200.
