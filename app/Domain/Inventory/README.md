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

* **Orders (wired in this branch)** — `Listeners\OrderStockListener` handles `Orders\Events\OrderSent / OrderSettled / OrderVoided` synchronously inside Orders'
  transaction, resolving product -> stock item(s) x `quantity_per_unit` through Catalog's `product_stock_link` (`Services\ProductStockLinks`) and calling
  `Contracts\InventoryConsumption`. Facility rule `stock_consumption_timing` (`SEND` default | `SETTLE`, on the facility's INVENTORY capability):
  SEND consumes on `OrderSent`, SETTLE on `OrderSettled`; `OrderVoided` always restores what the lines had consumed (no-op otherwise). Idempotent per order line.
  A shortfall at a location that disallows negative stock throws `insufficient_stock` (409, `meta.itemId/productId/lineId/availableQuantity`) which rolls the send back.
  The sale location is the facility's own store (default-flagged first), walking up parent facilities. Products without links consume nothing.
  Other callers use `Contracts\InventoryConsumption` directly: `consume(facilityId, ConsumptionLine[], 'order', orderId)`, `reverse(...)`, `shortages(...)`.
* **Catalog** — `Services\InventoryStockLevelProvider` replaces Catalog's `StockLevelProvider`: availability / `OUT_OF_STOCK` / `quantityOnHand` = the tightest
  linked item (on hand / quantity_per_unit) at the facility's sale location. Inventory adds the FK `product_stock_link.stock_item_id -> inventory_item`.
* **Ticketing** — `Contracts\RentalGateway`: `issueAsset/returnAsset` (tagged, atomic) and `issueQuantity/returnQuantity` (pooled, ledger).
* **Approvals** — manual adjustments and count variances use Orders' `ApprovalService` (action `inventory.adjustment`, required permission
  `inventory.adjustment.approve`): the request appears in `GET /approvals` and is decided through `POST /approvals/{id}/decision` (alias:
  `POST /inventory/adjustments/{id}/decision`); `Services\AdjustmentApprovalHandler` posts the ledger legs in the same transaction, or closes the request on
  reject/cancel/expiry. The handler re-checks the approve permission at the *location's* scope (site-wide for the Main Store).

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
