# Inventory module

Design: `architecture/09-inventory-movement-model.md`, `architecture/04 §3.4`. Contract: `api/openapi/v1.yaml` tag **Inventory**.

## Model

| Table | Role |
| --- | --- |
| `inventory_item`, `supplier`, `stock_location` | master data. Locations: one `MAIN_STORE` plus one sub-store per facility (`FACILITY_STORE`/`BAR`/`KITCHEN`), `WASTE`. `allow_negative` per location (audited when changed). `is_sale_default` picks the store a facility sells from. |
| `stock_movement` | **append-only ledger**, one row per (item, location) leg: signed `qty_delta`, `balance_after`, `reason`, counterpart location, `reference_type/id/line_id`, unit cost, actor, `approval_id`, `dedupe_key` (UNIQUE). DB triggers reject UPDATE and DELETE. |
| `stock_balance` | guarded projection of the ledger (`qty_on_hand`). Only `StockLedger` writes it. |
| `purchase_receipt(+_line)`, `stock_transfer(+_line)` | documents; their ledger legs share `reference_id`. |
| `stock_adjustment(+_line)` | a *request* (manual adjustment or count variance). Ledger rows only exist once it is POSTED. |
| `stock_count(+_line)` | physical count sheets. |
| `rental_asset` | tagged rentable equipment (status compare-and-set). |

`product_stock_link` belongs to Catalog and is not created here.

## Concurrency rules (why "the last unit" has exactly one winner)

`StockLedger::post()` is the only writer: deduction = `UPDATE stock_balance SET qty_on_hand = qty_on_hand - :q ... WHERE qty_on_hand - :q >= 0`
(0 rows -> `insufficient_stock`, 409, `meta.itemId/availableQuantity`); credits and negative-allowed locations use `INSERT ... ON DUPLICATE KEY UPDATE`.
Multi-leg documents post in canonical (location, item) order (no lock-order deadlocks). A failure anywhere rolls the whole document back
(savepoint) — no partial transfer. `dedupe_key` makes replays no-ops (after a duplicate-key error the winner is re-read with a locking read,
because a plain SELECT would still see the transaction's old REPEATABLE READ snapshot).

## Calling Inventory from other modules

* **Orders** — `App\Domain\Inventory\Contracts\InventoryConsumption`: `consume(facilityId, ConsumptionLine[], 'order', orderId)` inside your own
  transaction (deducts from the facility's default sale location, walking up parent facilities; throws `insufficient_stock` 409 so the line is rejected;
  idempotent per `lineRef` = order-line UUID), `reverse('order', orderId, [lineRefs])` for voids, `shortages()` as an advisory pre-check, and
  `ConsumptionService::timingFor(facilityId)` for the facility rule `stock_consumption_timing` (`SEND` | `SETTLE`, default `SEND`).
* **Ticketing** — `Contracts\RentalGateway`: `issueAsset/returnAsset` (tagged, atomic) and `issueQuantity/returnQuantity` (pooled, ledger).
* **Approvals** — `Contracts\ApprovalRequests` (default: minimal `approval` row). When Orders' approval service exists it re-binds this contract and its
  `POST /approvals/{id}/decision` must call `AdjustmentService::decide($adjustmentId, $approve, $note, $deciderStaffId)` for `entityType = StockAdjustment`.
  Until then `POST /inventory/adjustments/{id}/decision` decides directly.

## Endpoints (all `auth:staff`; mutating ones take `Idempotency-Key`)

Contract: `GET items|locations|balances`, `POST purchase-receipts|transfers|adjustments|wastage|counts`, `POST counts/{id}/post`.
Additions: `POST|PATCH items`, `POST|PATCH locations`, `GET|POST suppliers`, `GET movements`, `GET adjustments[/{id}]`,
`POST adjustments/{id}/decision`, `POST returns`, `GET counts[/{id}]`, `GET|POST rental-assets`. List responses use the contract's `{items, nextCursor}`.
Permissions are checked against the **location's scope** (facility subtree, or site for the Main Store): transfers need `inventory.transfer.create` at the
*source*; adjustments `inventory.adjustment.request` (posted at once only if the requester also holds `inventory.adjustment.approve`, else `202` + PENDING_APPROVAL).

## Operations

`php artisan r007:inventory:reconcile [--fix] [--json]` — nightly (02:30 Africa/Lagos) compares `stock_balance` to `SUM(stock_movement)`; exit 1 + `security_event`
on drift; `--fix` rebuilds the projection from the ledger (audited).
`php artisan r007:inventory:demo-seed` (also runs after `r007:demo-seed`) — Main Store + facility sub-stores, 64 items, opening stock via real receipt + transfers.
Config: `config('inventory.*')` (count variance threshold %, default timing, reconcile time).
