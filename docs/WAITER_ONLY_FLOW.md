# Waiter-only service flow

For facilities where nobody works a kitchen/bar screen or a cashier point yet. Switched on per facility with the operating rule
`service_flow = WAITER_ONLY` (admin: Facilities > Rules > Tabs & table service). `STANDARD` (default) changes nothing.

## What changes under WAITER_ONLY

| Step | Behaviour |
|---|---|
| Send | Lines are NOT routed to KDS stations: no prep tickets, lines go straight to `LOCKED`. Order is `SENT`. |
| Serve | `POST /orders/{id}/serve` by the waiter or a supervisor (`order.serve`). Now audited as `order.serve`. |
| Pay | `POST /waiter-flow/settle` - many served orders, any tabs, one or more tenders, receipt, tabs closed. No cashier, no cash session, no printed bill. |
| Cash | Cash tenders are written to the taker's `cash_in_hand_entry` ledger; handed over later with the normal cash-handover flow. |
| Card / transfer | A slip or transfer reference is mandatory (`reference_required`). |
| Supervisor | `waiterflow.manage`: sees every open tab with owner, pays on a waiter's behalf (payment keeps `owner_staff_id`), reassigns. |
| Hand-over | Waiter lists orders for another waiter; the receiver must accept. 30 minute expiry. Supervisor can force-reassign with a reason. |
| Print | Any receipt can be queued for a printer (`print_job`); a POS terminal or the admin portal prints it and marks it printed. |

## Endpoints (all under `/api/v1`)

* `GET  /waiter-flow/open-items?facilityId=&staffId=` - unpaid orders grouped by tab. Waiters get their own; managers get everyone's (+ per-waiter totals).
* `POST /waiter-flow/settle` `{facilityId, orderIds[], tenders[], customerName?}` -> `201` payment group + `receiptId`, `closedTabIds`. Header `Idempotency-Key`.
* `GET  /waiter-flow/waiters?facilityId=` - colleagues a tab can be handed to.
* `POST /waiter-flow/handovers` `{facilityId, toStaffId, orderIds?, note?}` (no orderIds = all my open orders)
* `GET  /waiter-flow/handovers?facilityId=&direction=incoming|outgoing|all&status=PENDING`
* `POST /waiter-flow/handovers/{id}/accept | reject | cancel`
* `POST /waiter-flow/reassign` `{facilityId, toStaffId, fromStaffId? | orderIds?, reason}` (`waiterflow.manage`)
* `POST /receipts/{id}/print-jobs` `{copies?}` (`receipt.print`); `GET /print-jobs?facilityId=&status=QUEUED`; `GET /print-jobs/{id}`; `POST /print-jobs/{id}/printed|failed|cancel`

Orders/tabs gain `responsibleStaffId` (who currently looks after them; `createdByStaffId` / `openedByStaffId` never change).

## Permissions

`waiterflow.settle` (own orders): WAIT_STAFF, BARTENDER, CASHIER, UNIT_SUPERVISOR, MANAGER. `waiterflow.manage`: UNIT_SUPERVISOR, MANAGER.
`receipt.print`: waiters, bartenders, cashiers, supervisors, managers, accountants. Waiters also gain `receipt.view`. OWNER holds all.

## Audit actions

`order.serve`, `payment.waiter_settle` (+ the usual per-payment `payment.capture`), `tab.handover.request|accept|reject|cancelled|expired|forced`,
`order.reassign`, `receipt.print.request|printed|failed|cancel`. All hash-chained in `audit_log`.

## Realtime

`tab-handover.updated` on `facility.{id}.orders` (`{handoverId, status, fromStaffId, toStaffId}`) and `order.updated` with `changed:["responsible"]`.

## Known gaps

* Hand-overs are recorded on the node where they happen (audit + `order.responsible_staff_id`); they are not replicated to the other node yet (no outbox event, to avoid FAILED inbox rows). Orders/payments sync as before.
* Payment is online-only (it needs the property or online server); offline tablets can take orders, not settle.
