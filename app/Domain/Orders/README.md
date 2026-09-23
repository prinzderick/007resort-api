# Catalog + Orders + Hospitality (KDS) + Approvals

Modules: `Catalog` (products, prices, availability), `Orders` (orders, tabs, tables, approvals), `Hospitality` (KDS stations, prep tickets).
Contract of record: `007resort-docs/api/openapi/v1.yaml` + `api/realtime.md`. Everything below is implemented as specified unless listed under "Deviations".

## For other modules

| Need | Use |
| --- | --- |
| Deduct stock when an order is sent (SALE) | listen to `App\Domain\Orders\Events\OrderSent{orderId, facilityUnitId, lines[{lineId, productId, qty}]}` — dispatched **synchronously inside the send transaction**; throw `ApiProblem::conflict('insufficient_stock', ...)` to veto/roll the send back |
| Reverse stock on void | `OrderVoided{orderId, facilityUnitId, lines[{lineId, productId, qty, sent}], reason, approvalId}` (`sent` = stock was consumed) |
| Report on a settled order | `OrderSettled{orderId, facilityUnitId, total, amountPaid, tabId}` |
| Payments: record money against an order | `OrderSettlementService::applyPayment($orderId, '2500.0000')` (rejects over-payment with `amount_mismatch`; SERVED + fully paid => SETTLED), `applyRefund`, `markSettled`, `markTabSettled($tabId)` — call inside the payment transaction; they lock the order row |
| Facility payment rules | `OperatingRules::forFacility($id)`: `payment_timing` PAY_FIRST / PAY_AFTER_SERVICE (alias PAY_BEFORE_LEAVING) / OPEN_TAB (aliases PAY_ON_EXIT, PAY_AT_RECEPTION), `approval_threshold_amount`, `require_approval_for` |
| Product stock link | table `product_stock_link(product_id, stock_item_id, quantity_per_unit)` (no FK: Inventory owns the item table); rebind `Catalog\Contracts\StockLevelProvider` to report `quantityOnHand` / `OUT_OF_STOCK` |
| New approval action | `app(ApprovalService::class)->registerHandler('inv.adjust', $handler)` (implements `ApprovalHandler::approved/discarded`); create with `->request(...)`; gate with `->gate($executePerm, $approvePerm, $facilityId, $stepUpToken, $entityId)` |
| Realtime | `App\Domain\Orders\Broadcast\*` events (after-commit, `ShouldBroadcastNow`, no queue worker needed) |

Table names (schema commit `e4d52a9`): `product_category, product, price_list, price, tax_rate, prep_route, product_facility, product_stock_link, prep_route_station, kds_station, prep_ticket, prep_ticket_item, dining_table, tab, order, order_line, line_adjustment, line_void, shift, order_number_counter, prep_ticket_counter`; `approval` was extended (action, entity, payload, required_permission, ...). `order` is a reserved word: backtick it in raw SQL.

## Rules (server side, decimal maths only)

* Line snapshot at add time: name, sku, unit price, tax rate/inclusive flag, prep route. Client never sends prices.
* Price = default list, facility price beats list-wide (`price.facility_unit_id`). Tax: VAT off (ADR-0011 default) => 0; on => product rate else org default; `pricesTaxInclusive` carves tax out of the price, otherwise adds it. `subtotal` = sum of gross (pre-discount), `total` = sum of line totals.
* A product is sold at a facility only if a `product_facility` row exists (`capability_disabled` otherwise); 86'd => `409 product_unavailable`.
* `If-Match` (ETag = `row_version`, `"3"`): missing = 428, stale = 412 `concurrency_conflict`. Client ids (order/line/tab): same id + same body replays, different body = 409 `concurrency_conflict`, non-UUIDv7 = 422.
* Order: `DRAFT -> SENT -> IN_PREPARATION -> READY -> SERVED -> SETTLED` (`SENT -> SERVED` when nothing is routed), `VOIDED`, holding state `PENDING_APPROVAL`. Lines only change while DRAFT. Prep tickets `NEW -> ACCEPTED -> IN_PROGRESS -> READY -> DISPENSED` (skips NEW->IN_PROGRESS, ACCEPTED->READY), `CANCELLED` only via void.
* Locking order: order row first, then tickets/lines (never the reverse).
* Sensitive actions: `void`, `discount`, `price override`, `comp`. Caller lacks `*.execute` => 403; holds `*.approve` or presents a valid `X-Step-Up-Token` => executes now; otherwise `202 PENDING_APPROVAL` + an `approval` row. A supervisor (holding the approval's `requiredPermission`, never the requester) decides; approving applies the action + writes the audit rows in the same transaction. Small discounts (<= facility `approval_threshold_amount`) and unsent-draft voids need no approval unless `require_approval_for` says so.
* Routing: a line's prep route (KITCHEN/BAR/NONE) -> the station serving that route at the order's facility, or the `prep_route_station` mapping (Restaurant food is cooked in the Main Kitchen). NONE / no station => the line is LOCKED and served directly.

## Deviations / decisions (see PR body)

* Lists use the contract envelope `{items, nextCursor}`.
* Extra endpoints beyond the contract: `POST/PATCH /catalog/categories|products`, `PUT /catalog/products/{id}/price`, `GET /catalog/prep-routes|tax-rates`, `POST /tables/{id}/assign|transfer`.
* `Product.active` is false when the product is inactive OR 86'd at that facility OR has no price (clients hide inactive items).
