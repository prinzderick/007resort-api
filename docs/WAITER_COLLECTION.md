# Waiter collection: bill, collect, confirm, hand over

Contract: `docs/openapi/v1.yaml` (tags **Bills**, **Collections**, **Cash handover**, **Payment terminals**, **Collection policy**; every path is `x-additive: true`).
Code: `app/Domain/Orders` (bill) and `app/Domain/Payments` (collection, confirmation, terminals, handover, policy).
Related design: `architecture/07` (payment states), `architecture/08` (order states).

## The owner's rule

The cashier (or the waiter) **prints the bill**. A waiter then takes a portable card machine / cash / a transfer link to the customer's table and
**collects**. **A waiter can never mark a bill paid.** Everything a waiter collects by hand is `PENDING_CONFIRMATION` until a cashier or supervisor
(who holds `payment.confirm`, and is not the collector) verifies it against the slip / bank alert / cash count. Money that the payment provider itself
confirms (Paystack pay link, Paystack transfer, a future integrated terminal) is captured directly by the server, never by a person.

```
order SERVED --POST /orders/{id}/bill--> bill printed (order frozen)
   --POST /orders/{id}/collections--> payment PENDING_CONFIRMATION  (order balance untouched, "collected, awaiting confirmation")
        --POST /payments/{id}/confirm (cashier)--> CAPTURED -> order paid/SETTLED, receipt issued
        --POST /payments/{id}/reject  (cashier)--> REJECTED  (audit + security event + supervisor alert)
        --(nobody acts for pending_collection_expiry_minutes)--> EXPIRED (alert)
   PAY_LINK / Paystack transfer: payment AUTHORIZING -> webhook/verify -> CAPTURED (server-confirmed, waiter cannot confirm)
```

## 1. Bill (pre-bill)

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| POST | `/orders/{id}/bill` | `bill.print` (order's facility) | freezes the order; returns the pre-bill; a second call is a **reprint** (count + audit) |
| POST | `/orders/{id}/bill/cancel` | `bill.cancel.execute` (+ `bill.cancel.approve` to execute at once) | reopen; `{reason}`; 200 done or **202** `PENDING_APPROVAL` (Approval); refused (409 `collections_pending`) while any collection is pending/captured |

* **Decision: `order.status` is NOT changed** (adding a status would break every client's switch and the settlement state machine). The bill state is
  additive fields on the Order / OrderSummary: `billState` (`OPEN` | `BILL_PRINTED`), `billPrintedAt`, `billPrintCount`, `billPrintedByStaffId`,
  `billReopenCount`, `awaitingPayment` (bill printed and money still owed), `pendingCollected` (sum of pending collections) and `collectable`
  (what may still be collected = total - captured - pending). UIs show "Awaiting payment" when `awaitingPayment`, and "collected, awaiting confirmation" when `pendingCollected > 0`.
* While `billState = BILL_PRINTED`: adding/removing lines, send, discount/price-override/comp and void are refused with **409 `order_billed`**.
  Serving is still allowed. Cancel the bill to change the order.
* Printable statuses: `SENT`, `IN_PREPARATION`, `READY`, `SERVED` (and `DRAFT` at PAY_FIRST facilities) with at least one live line and `total > 0`; otherwise 409 `order_state_invalid`.
* Reopened bills: if the facility rule `pre_bill_requires_supervisor_if_reopened` is true (default) and the bill was cancelled before, printing again needs a
  caller with `bill.cancel.approve` or an `X-Step-Up-Token` for it (else 403 `supervisor_required`).
* Response `200`: `{order, bill, reprint}`. `bill` (`PreBill`) is 80mm-ready: `printLines` (48 columns; 32 with `RECEIPT_COLUMNS=32`) start with
  `*** BILL - NOT A RECEIPT ***` and end with `NOT A RECEIPT - NOT PROOF OF PAYMENT`. It carries `lines`, `subtotal`, `discountTotal`, `taxLines`
  (empty unless the organization is VAT registered, ADR-0011), `taxTotal`, `total`, `amountPaid`, `balanceDue`, `tableLabel`, `waiter {id,name}`,
  `printedAt`, `printCount`, `reprint` and `payLink {enabled, reference, url, qrPayload}` (only when rule `bill_pay_link_enabled` is true and `PAY_LINK_BASE_URL` is configured; the reference is the order number).
* Audit: `order.bill.print`, `order.bill.reprint`, `order.bill.cancel` (+ `order.bill.cancel.request` for an approval). Outbox `OrderUpdated` (`event: bill.printed|bill.cancelled`). Realtime `bill.printed`.

## 2. Collection

`POST /orders/{id}/collections` (Idempotency-Key + optional client UUIDv7 `id`). Permission `payment.collect` at the order's facility scope.

```json
{ "id": "<uuidv7?>", "tenderType": "CASH|CARD_TERMINAL|TRANSFER|PAY_LINK", "amount": "9000.0000",
  "tendered": "10000.0000",                       // CASH only (change = tendered - amount)
  "terminalId": "<uuid?>", "approvalCode": "?", "slipReference": "?", "last4": "?",   // CARD_TERMINAL
  "bankReference": "?", "channel": "MANUAL|PAYSTACK",                                // TRANSFER (PAYSTACK = per-bill virtual account)
  "customerEmail": "?",                                                              // PAY_LINK / PAYSTACK transfer (else the configured default)
  "note": "?", "clientCreatedAt": "2026-09-24T10:15:00Z" }
```

Response **201** `{payment, order, payLink?, transferAccount?}` (`Idempotent-Replayed: true` on a replay):

| Tender | Resulting payment `status` | Who confirms |
| --- | --- | --- |
| CASH | `PENDING_CONFIRMATION` (requires cash holding to be allowed, see section 6) | cashier/supervisor (`payment.confirm`) |
| CARD_TERMINAL | `PENDING_CONFIRMATION` (manual bank POS machine: cashier checks the slip). If the terminal's adapter reports `CONFIRMED` the server captures directly | cashier, or the terminal adapter |
| TRANSFER (`MANUAL`) | `PENDING_CONFIRMATION` (`bankReference` = what the customer showed) | cashier against the bank alert |
| TRANSFER (`PAYSTACK`) | `AUTHORIZING`; `transferAccount {bankName, accountNumber, accountName, expiresAt}` for the customer to pay | server (webhook/verify) |
| PAY_LINK | `AUTHORIZING`; `payLink {authorizationUrl, accessCode, reference}` | server (webhook/verify) |

* Ledger tender types stay the existing ones: `CARD_TERMINAL` -> `POS_TERMINAL`, `PAY_LINK` -> `CARD`. The API value is `payment.collection.tender`. `payment.takenByStaffId` = the waiter.
* **Preconditions** (in this order): rule `waiter_collection_enabled` (409 `collection_disabled`); bill printed (409 `order_not_billed`); order payable (409 `order_state_invalid`, the facility payment timing rule still applies);
  the caller's **device is a tablet currently checked out to that staff at that facility** (403 `device_not_checked_out`; a device with no checkout, or checked out to someone else, is refused);
  the terminal (if given) exists, is ACTIVE, at this facility and not assigned to someone else (422 `terminal_unavailable`); tender allowed (see 6: 403 `cash_holding_not_allowed`, 409 `cash_limit_exceeded`).
* **Over-collection**: under the order row lock, `amount <= total - captured - pending`. Pending = `PENDING_CONFIRMATION` collections + `AUTHORIZING` collections (pay links / provider transfers reserve the balance until they are paid, cancelled or expired). Violations: **409 `over_collection`** (`details: balanceDue, pendingCollected, collectable`). CASH may carry `tendered > amount` (change), nothing else may.
  A cashier taking a normal `POST /payments` / tab settle / Paystack initialize on the same bill subtracts the same pending amount (409 `pending_collection_exists`), so waiter + cashier can never over-collect.
* **Duplicates**: same client `id` + same body replays the original (200/201 + `Idempotent-Replayed`), same id + different body 409 `concurrency_conflict`. The same `approvalCode`/`slipReference`/`bankReference` on a second live collection is 409 `duplicate_reference`.
* `collection_requires_confirmation` (rule, list of tender types, default `CASH, CARD_TERMINAL, TRANSFER`): a tender type **removed** from the list is captured at once **only when the collector also holds `payment.take` at the facility** (i.e. a cashier collecting at the table). A waiter without `payment.take` always creates a pending collection, whatever the rule says.
* Side effects (one DB transaction): payment + allocation + `payment_collection` row, cash-in-hand entry (CASH), audit `payment.collect`, outbox `PaymentCollected`, realtime `payment.collected`.

## 3. Confirmation

| Method | Path | Permission | Result |
| --- | --- | --- | --- |
| POST | `/payments/{id}/confirm` `{matchedReference?, note?}` | `payment.confirm` at the payment's facility; **not the collector** (403 `self_confirmation_forbidden`) | `PENDING_CONFIRMATION` -> `CAPTURED`, order settled through `OrderSettlementService`, receipt issued, cash linked to the confirming cashier's open session |
| POST | `/payments/{id}/reject` `{reason}` | `payment.confirm` | -> `REJECTED`; audit + `security_event payment.collection_rejected` (WARNING) + realtime alert |
| POST | `/payments/{id}/cancel` `{reason}` | the collector (only for `AUTHORIZING` pay-link/transfer, never for cash) or `payment.confirm` | -> `CANCELLED` after asking Paystack (409 `already_paid` if it was paid) |
| GET | `/payments?status=PENDING_CONFIRMATION&facilityId=&collectedBy=&orderId=&tenderType=` | `payment.view` or `payment.confirm` (facility) ; a waiter without a facility filter sees only their own | cursor page; also accepts `filter[...]` |

* **Confirm is idempotent**: a second confirm of a payment already captured by confirmation returns 200 with the same body (`Idempotent-Replayed`), no second capture, receipt or outbox row.
  Confirm of `REJECTED`/`EXPIRED`/`CANCELLED` is 409 `payment_state_invalid`; **reject after confirm is 409 `payment_state_invalid`** (use refund/reversal). Reject is idempotent for the same reason.
  Confirm of an auto-confirm payment (`AUTHORIZING`) is 409 `auto_confirm_only`.
* Confirm re-checks under the locks that `captured + amount <= total`; if a provider-confirmed online payment landed first, confirm is 409 `balance_changed` and the cashier should reject.
* CASH confirm needs the confirming cashier to have an OPEN cash session (409 `cash_session_required`, rule `require_cash_session`).
* Every payment has at most one decision row (`payment_collection_decision`, append-only): `CONFIRMED | REJECTED | EXPIRED | CANCELLED`, mode `MANUAL | PROVIDER | SYSTEM`.
* Outbox `PaymentCompleted` + `PaymentConfirmed` / `PaymentRejected`, in-process `PaymentCaptured` event.
* **Auto-expiry**: `php artisan r007:payments:expire-pending-collections` (scheduled every minute, module provider) moves collections older than the facility's `pending_collection_expiry_minutes`
  (default `PAYMENTS_COLLECTION_EXPIRY_MINUTES=30`) to `EXPIRED` (they stop reserving the balance) and raises `security_event payment.collection_expired` + realtime `payment.alert`. Expired **cash stays in the waiter's cash-in-hand** (the waiter remains accountable until handover); the bill can be paid again normally.
  Expired `AUTHORIZING` links are re-verified with Paystack first (captured if paid, else `CANCELLED`).

## 4. Auto-confirm (Paystack)

`PAY_LINK` and `TRANSFER` with `channel: PAYSTACK` use the existing Paystack adapter (reference `R007-...`, metadata `orderNumbers`, `collection: true`); transfer uses Paystack's dynamic
virtual account (`POST /charge` with `bank_transfer`, dev stand-in `scripts/dev-paystack.php` implements it). The existing webhook (`POST /payments/webhooks/paystack`, HMAC) or
`GET /payments/paystack/verify/{reference}` asks Paystack server-to-server and captures **once** (UNIQUE provider event + payment row lock; 3 duplicate webhooks = 1 capture).
On capture the collecting waiter's device gets `payment.confirmed` (`mode: PROVIDER`). A waiter (or anyone) cannot confirm these manually.

## 5. Payment terminals

`PaymentTerminalAdapter` (Contracts): `code()`, `initiateCharge(terminal, reference, amount, ctx)` -> `{status: PENDING|CONFIRMED|FAILED, providerReference}`, `queryStatus(reference)`, `parseCallback(rawBody, signature)`, `void(reference)`, `refund(reference, amount)`.
Default `ManualBankTerminalAdapter` (`provider = MANUAL_BANK`): a normal bank POS machine with no API: always `PENDING`, so the cashier confirms against the slip. `PaystackTerminalAdapter` is registered behind
`PAYMENTS_TERMINAL_PAYSTACK_ENABLED=false` and returns 501 `terminal_provider_unavailable` (drop-in point for a real integration; endpoints do not change). When an adapter reports `CONFIRMED`, the server captures with `mode: PROVIDER`.
Provider callbacks: `POST /payments/terminal-callbacks/{provider}` (signature-authenticated; 400 for providers without callbacks).

Registry `payment_terminal` (`id, facilityId, provider MANUAL_BANK|PAYSTACK_TERMINAL, label, serial, status ACTIVE|INACTIVE|RETIRED, assignedDeviceId, assignedStaffId`):
`GET /payment-terminals?facilityId=&status=` (`payment.collect` or `device.manage`), `POST /payment-terminals`, `GET/PATCH /payment-terminals/{id}` (`device.manage`, audited, outbox `PaymentTerminalChanged`).
`terminalId` is recorded on the collection.

## 6. Cash holding policy, cash-in-hand and handover

Waiter cash holding is **disabled by default**. Effective policy per waiter = staff override, else facility rule.

* Facility rules: `waiter_cash_holding` (bool, default false) and `waiter_cash_in_hand_limit` (money, optional, empty = unlimited; the old name `max_cash_in_hand_before_handover` is honoured as an alias).
* Per-staff override `cash_holding = INHERIT | ALLOW | DENY` (+ optional personal `cashLimit`): `PATCH /staff/{id}/collection-policy` (`staff.manage`, audited `staff.collection_policy.update`, outbox `StaffCollectionPolicyChanged`).
  `GET /staff/{id}/collection-policy[?facilityId=]` (self or `staff.manage`) returns the **effective** policy: `cashHolding {allowed, source: facility|staff, limit, limitSource}`, `allowedTenders`, `staffOverride`, `facilityRule`.
* Not allowed: a CASH collection is **403 `cash_holding_not_allowed`** ("Cash holding is not enabled for you; send the customer to the cashier"); CARD_TERMINAL / TRANSFER / PAY_LINK still work. Allowed but `cash-in-hand + amount > limit`: **409 `cash_limit_exceeded`** (hand over first).
* Cash-in-hand is an append-only ledger (`cash_in_hand_entry`): `+amount` per CASH collection, `-declared` per received handover. It is **not** reduced by reject/expire (the cash is still physically with the waiter until handed over).
* Handover: `POST /cash-handovers {id?, declaredAmount, note?}` (`cash_handover.create`; 403 `cash_holding_not_allowed` if holding is denied and nothing is in hand; 422 if declared > cash in hand) creates `PENDING_RECEIPT`.
  `POST /cash-handovers/{id}/receive {countedAmount, note?}` (`cash_handover.receive`, not the waiter) records `variance = counted - declared` (negative = short, positive = over), reduces the waiter's cash-in-hand by `declaredAmount`, audits and raises `security_event cash_handover.variance` when non-zero.
  If `abs(variance) > cash_handover_max_variance` (rule, default 500.0000) the status is `PENDING_SIGNOFF` until `POST /cash-handovers/{id}/signoff {note}` (`cash_handover.signoff`, not the receiver); otherwise `RECEIVED`.
  `GET /cash-handovers`, `GET /cash-handovers/{id}`, `GET /staff/{id}/cash-in-hand` (own, or `cash_handover.view`): `{cashInHand, limit, handoverRequired, oldestUncollectedAt, pendingCollections, openHandovers, unsignedShortfall}`.
* Outbox `CashHandoverRecorded`; realtime `cash-handover.received` to the waiter's device.

## 7. Operating rules (typed, `operating_rule` keys)

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `waiter_collection_enabled` | bool | true where the facility has `TABLE_SERVICE`, else false | waiters may collect at tables |
| `waiter_cash_holding` | bool | false | waiters may hold cash |
| `waiter_cash_in_hand_limit` | money | none | cash-in-hand ceiling before handover is forced |
| `collection_requires_confirmation` | multi_enum (CASH, CARD_TERMINAL, TRANSFER) | all three | see section 2 |
| `pending_collection_expiry_minutes` | duration (minutes, 1..10080) | 30 (env) | auto-expiry window |
| `pre_bill_requires_supervisor_if_reopened` | bool | true | reprint after a cancelled bill needs a supervisor |
| `bill_pay_link_enabled` | bool | false | print the pay reference / QR on the pre-bill |
| `cash_handover_max_variance` | money | 500.0000 | above this a handover needs supervisor sign-off |

They are exposed to apps in `GET /facilities/{id}/capabilities` -> `operatingRules` (camelCase, e.g. `waiterCashHolding`). The admin catalogue (api-config `RuleDefinitions`) needs these definitions; group `Payments & cash`, capability `TABLE_SERVICE`/`PAYMENT_ACCEPTANCE`, enforcement `server`:
`waiter_collection_enabled` bool (high), `waiter_cash_holding` bool (high), `waiter_cash_in_hand_limit` money NGN (high), `collection_requires_confirmation` multi_enum [CASH,CARD_TERMINAL,TRANSFER] (high),
`pending_collection_expiry_minutes` duration min 1 max 10080 (medium), `pre_bill_requires_supervisor_if_reopened` bool (medium), `bill_pay_link_enabled` bool (low), `cash_handover_max_variance` money NGN (high).

## 8. Permissions (seeded by migration, role bundles are data)

`bill.print` (waiter, bartender, cashier, supervisor, manager), `bill.cancel.execute` (waiter, bartender, cashier: **requires approval**) and `bill.cancel.approve` (supervisor, manager),
`payment.collect` (waiter, bartender), `payment.confirm` (cashier, supervisor), `cash_handover.create` (waiter, bartender), `cash_handover.receive` (cashier, supervisor), `cash_handover.signoff` (supervisor),
`cash_handover.view` (cashier, supervisor, manager, accountant), `device.manage` (IT admin, manager). The seeded **Manager holds every collection permission** (Identity's escalation guard requires a role granter to hold every permission of the roles it may assign); authorization is permission-based, never role-name-based, so a custom role *named* Manager without `payment.confirm` is denied (tested).

## 9. Realtime (private channels, envelope of `api/realtime.md`)

| Event | Channels | `data` |
| --- | --- | --- |
| `bill.printed` | `device.{printingDeviceId}`, `facility.{id}.orders` | `{order (OrderSummary), printCount, reprint}` |
| `payment.collected` | `facility.{id}.orders` (cashier POS), `device.{collectingDeviceId}` | `{payment, orderId, orderNumber, tableLabel, collectedByStaffId, amount, tender}` |
| `payment.confirmed` | `device.{collectingDeviceId}`, `facility.{id}.orders` | `{payment, orderId, mode: MANUAL\|PROVIDER, receiptId}` |
| `payment.rejected` | `device.{collectingDeviceId}`, `facility.{id}.orders` | `{payment, orderId, reason}` |
| `payment.expired` | `device.{collectingDeviceId}`, `facility.{id}.orders` | `{payment, orderId, reason}` |
| `payment.alert` | `facility.{id}.orders` only (supervisor POS / tablets) | `{kind: REJECTED\|EXPIRED, message, payment, orderId, reason}` |
| `cash-handover.received` | `device.{waiterDeviceId}`, `facility.{id}.orders` | `{handover}` |

Hints only: reload over REST after any of them.

## 10. Errors added

`order_not_billed`, `order_billed`, `collections_pending`, `collection_disabled`, `device_not_checked_out`, `cash_holding_not_allowed`, `cash_limit_exceeded`, `over_collection`, `pending_collection_exists`,
`duplicate_reference`, `self_confirmation_forbidden`, `payment_state_invalid`, `auto_confirm_only`, `already_paid`, `terminal_unavailable`, `terminal_provider_unavailable` (501), `terminal_charge_failed`, `terminal_retired`,
`supervisor_required`, `bill_not_printed`, `already_received`, `self_receipt_forbidden`, `self_signoff_forbidden`, `handover_state_invalid`, all as RFC 7807 with `code`.

## 11. Tests and how to run them

`tests/Feature/Payments/WaiterCollectionTest.php`, `CashHandoverTest.php` (transactional, real MySQL) and `WaiterCollectionConcurrencyTest.php` (separate PHP processes released at the same instant):
two waiters racing for one bill (exactly one wins), 10 partial collections (exactly 4 x 2000 fit into 9000), waiter vs cashier on the same bill, 6 simultaneous confirms of one payment (one capture / receipt),
confirm-vs-reject race (one decision), Paystack webhook delivered 3x at once (one capture), cash limit under concurrency, one handover received once.
Concurrency rule of thumb used here (same as PaymentService): take the row locks first; InnoDB REPEATABLE READ pins its snapshot at the first plain read, so anything read after a lock *wait* must be a locking read (`cashInHand(..., locking: true)`) or come after the lock.

## 12. Cloud sync

Outbox event types `PaymentCollected`, `PaymentConfirmed`, `PaymentRejected` (also carries `decision: EXPIRED|CANCELLED`), `CashHandoverRecorded`, `PaymentTerminalChanged`, `StaffCollectionPolicyChanged` are written in the business transaction.
Like the existing `PaymentCompleted`, the Cloud node has no applier for them yet (the inbox marks unknown types FAILED until one exists).

## 13. Known gaps

Confirming several cash collections in one call at handover, per-line bill splitting, waiter-to-waiter table hand-off of pending collections, a personal-device (non-tablet) collection mode,
real Paystack terminal integration (stub only), refunding a provider-captured collection through Paystack's refund API (recorded with `providerActionRequired`, see PAYMENTS.md).
