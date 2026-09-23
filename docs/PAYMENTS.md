# Payments module (`app/Domain/Payments`)

Contract: `docs/openapi/v1.yaml` (Payments, Cash sessions, Receipts, Tabs settle). Design: `architecture/07-payment-state-model.md`,
`04 §3.3`, ADR-0009 (Paystack behind an adapter), ADR-0011 (VAT is admin-settable, default off).

## Endpoints

| Method | Path | Permission (scope) | Notes |
| --- | --- | --- | --- |
| POST | `/payments` | `payment.take` (facility) | allocations + tenders CASH/CARD/TRANSFER/POS_TERMINAL; split needs `payment.split`; 201 |
| POST | `/tabs/{id}/settle` | `order.settle` (tab's facility) | pays the full balance of every open order on the tab; cash over-tender = change; 200 |
| GET | `/payments`, `/payments/{id}` | `payment.view` (facility) or own takings | cursor pagination |
| POST | `/payments/{id}/refund` | `refund.execute` (+ `refund.approve` for the approver) | 201 applied, or **202** `PENDING_APPROVAL` (Refund body + `approval`) |
| POST | `/payments/{id}/reversal` | `payment.reversal.execute` (+ `payment.reversal.approve`) | same-session correction; 201 / 202 |
| POST | `/payments/paystack/initialize` | `payment.take` | AUTHORIZING payment, returns `authorizationUrl` |
| GET | `/payments/paystack/verify/{reference}` | own payment or `payment.view` | asks Paystack; capture is idempotent with the webhook |
| POST | `/payments/webhooks/paystack` | none (HMAC-SHA512 signature) | 401 bad signature; 200 `{received, duplicate}` |
| POST/GET | `/cash-sessions`, `/cash-sessions/{id}`, `/cash-sessions/{id}/close` | `cash_session.open/view/close` | `close_any` to close someone else's |
| POST | `/cash-sessions/{id}/movements` | `cash_movement.record` | paid-in / paid-out / drop (additive) |
| GET | `/receipts/{id}[?reprint=true]`, `/orders/{id}/receipt` | `receipt.view` (+ `receipt.reprint`) | |

Every mutating endpoint takes `Idempotency-Key`. Offline clients may also send a UUIDv7 `id` per tender (same id + same body =
replay of the original result; different body = 409 `concurrency_conflict`).

## Money rules

* `DECIMAL(19,4)`, bcmath, decimal strings; NGN only. Paystack amounts are kobo integers on the wire (converted with bcmath;
  online amounts must be whole kobo).
* `payment.amount` = money applied to the bill. Cash: `tendered >= amount`, `changeGiven = tendered - amount`. Non-cash cannot be
  over-tendered. `sum(tenders) == sum(allocations)` (422 `amount_mismatch`). Partial settlement is allowed (order stays open).
* Tab settle: tenders must cover the balance (422 `amount_mismatch` otherwise); excess is taken off the cash tender(s) as change.
* Order `paid` is always derived from the ledger (`payment_allocation` of CAPTURED / PARTIALLY_REFUNDED / REFUNDED payments), never
  from a running counter. A refund does not re-open the order; a reversal does.
* A POS/transfer `reference` may back one live payment (UNIQUE generated column) -> 409 `duplicate_reference`.

## Concurrency (why it is safe)

1. Settlements lock the tab row then the order rows (ascending id) `FOR UPDATE` as their FIRST statements, and only then read
   balances (InnoDB REPEATABLE READ snapshots at the first non-locking read, so post-lock reads are fresh). Loser gets 409
   `balance_changed`.
2. Cash: payments take a shared lock on their `cash_session` row, `close` takes it exclusively -> nothing is booked into a session
   after it closed. One OPEN session per cashier and per device via UNIQUE indexes on generated columns.
3. Refunds/reversals lock the payment row; `refunded_amount` is checked under that lock, capped by a CHECK and a trigger.
4. Paystack: `UNIQUE(provider, provider_event_id)` + payment row lock + status check. HTTP to Paystack happens outside our transaction.
5. Lock order everywhere: tab -> orders -> cash session; refunds/reversals: payment -> orders -> cash session.

Tests: `tests/Feature/Payments/PaymentsConcurrencyTest.php` (real processes, real MySQL).

## Ledger immutability

App code has no UPDATE/DELETE path on `payment_allocation`, `refund`, `reversal`, `provider_event`, `cash_movement`, `receipt`,
`receipt_reprint`; `payment` only moves along its state machine. MySQL triggers (`2026_09_24_100400`) enforce it in the database
(`R007_LEDGER_IMMUTABLE`). **Production must also** `REVOKE UPDATE, DELETE` on those tables from the app DB user (except UPDATE on
`payment` and `cash_session`), and the migration user needs `TRIGGER` privilege. Test helper `TestData::wipe()` uses TRUNCATE for
tables with delete-triggers.

## Sensitive actions and approvals

`refund.execute` / `payment.reversal.execute` are held by cashiers **with `requires_approval = 1`**. The action is applied at once if
the caller holds the matching `.approve` permission at the facility, or presents an `X-Step-Up-Token` (`POST /auth/staff/step-up`
for `refund.approve` / `payment.reversal.approve`, entity `Payment`). Otherwise a PENDING `approval` row (action `payment.refund` /
`payment.reversal`, payload = request) is created and 202 returned. Facility rules can also require it (`require_approval_for`,
`approval_threshold_amount`). When Orders' approval-decision service APPROVES such an approval it must call
`PaymentApprovalHandler::apply($approvalId, $deciderStaffId)` in the decision transaction (see "Integration points").

## Facility operating rules read by Payments (`operating_rule.rule_key`)

`payment_timing` (`PAY_FIRST` | `PAY_ON_EXIT` | `PAY_AFTER_SERVICE` | `ANY`, default `ANY`; on-exit/after-service only settle SERVED
orders), `require_cash_session` (default true), `require_approval_for` (comma list incl. `payment.refund`, `payment.reversal`),
`approval_threshold_amount`.

## Receipts

Immutable snapshot at issue time (`receipt.payload`), number `RCP-YYYYMMDD-NNNNNN` per site per Lagos business day. `taxTotal`
is 0 and no VAT line / TIN is emitted unless `organization_tax_setting.vat_enabled` was on at issue time. `printLines` = 48-column text
for 80mm printers (`RECEIPT_COLUMNS=32` for 58mm). Reprints are counted in `receipt_reprint`, audited, and marked `duplicate`.

## Integration points (ports)

* `Contracts\OrderPort` (default `Services\DbOrderPort`): locks/reads Orders' tables; the WRITE half (`applyPayment`, `applyReversal`,
  `markTabSettled`) is the seam for Orders' `OrderSettlementService::markSettled` - swap the three methods, nothing else changes.
* `Contracts\ApprovalPort` (default `Services\DbApprovalPort`) writes the shared `approval` table.
* `Contracts\PayableSubjectResolver`: Booking / Membership bind it to take Paystack payments for a booking or membership; listen for
  `Events\PaymentCaptured` (carries `subjectType`/`subjectId`) to confirm/activate.
* `Contracts\PaymentProviderAdapter`: Paystack today (`Provider\PaystackAdapter`); Flutterwave = one more class.
* Outbox events: `PaymentCompleted` (in-person), `OnlinePaymentConfirmed` (provider), `PaymentReversed` (`kind` REFUND | REVERSAL),
  `CashSessionOpened`, `CashSessionClosed`. Audit actions: `payment.capture`, `payment.capture.online`, `payment.refund(.requested)`,
  `payment.reverse`, `payment.reversal.requested`, `payment.paystack.initialize`, `cash_session.open|close|movement`, `receipt.reprint`.

## Environment (placeholders in `.env.example`)

`PAYSTACK_SECRET_KEY` (API key AND webhook HMAC key), `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_BASE_URL`, `PAYSTACK_CALLBACK_URL`,
`PAYSTACK_TIMEOUT_SECONDS`, `PAYSTACK_WEBHOOK_ALLOWED_IPS` (optional), `RECEIPT_BUSINESS_NAME`, `RECEIPT_SITE_ADDRESS`,
`RECEIPT_FOOTER`, `RECEIPT_TIMEZONE`, `RECEIPT_COLUMNS`, `PAYMENT_REVERSAL_WINDOW_HOURS`. Configure the webhook URL in the Paystack
dashboard as `https://<cloud-host>/api/v1/payments/webhooks/paystack` (Cloud node is the public receiver).

## Known gaps

Paystack refund API call (refunds of PAYSTACK payments are recorded with `providerActionRequired`), settlement/reconciliation
endpoints (tables exist), receipt `qrPayload` (ticket QR), per-order-line refunds, `Refund.status` `FAILED`.
