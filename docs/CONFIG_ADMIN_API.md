# Configuration management API (admin portal contract)

Everything an administrator needs to run the property from the admin UI without engineers: facilities, capabilities, typed operating
rules, operating points / KDS stations / tables, device assignment, catalogue configuration (+ CSV import/export), tickets, memberships,
booking rules, payment methods, receipt settings, business profile, roles and permissions, onboarding progress, global search and change history.

All endpoints are under `/api/v1`, authenticated with a staff bearer token, camelCase JSON, RFC 7807 errors (`application/problem+json`, stable `code`).
Machine-readable contract: `docs/openapi/v1.yaml` (every path below is marked `x-additive: true`). Base conventions: `docs/MODULES.md`.

**Status legend:** `[built]` = merged in this branch and tested, `[built]` = contract fixed, implementation in progress (the shape will not change).

## 0. Conventions that apply to every endpoint

| Topic | Rule |
| --- | --- |
| Permissions | Permission-based only (never role names). `a|b` = any of. OWNER always holds every permission below (asserted by a test). Missing permission -> `403 permission_denied` with `permission`. |
| Creating | `Idempotency-Key` header required on `POST` create endpoints (replay returns the original response with `Idempotent-Replayed: true`). `PATCH`/`PUT`/state-change `POST`s are version-checked instead. |
| Optimistic concurrency | Reads return `ETag: "<rowVersion>"`; edits require `If-Match: "<rowVersion>"`. Missing -> `428 concurrency_conflict`; stale -> `412 concurrency_conflict` (nothing is changed; reload and re-apply). A no-op edit does not bump the version. |
| Validation | `422 validation_failed` with `errors: { "<field path>": ["message", ...] }` (e.g. `rules.payment_timing`). Specific 422 codes: `capability_dependency`, `danger_confirmation_required`, `facility_cycle`, `import_validation_failed`. |
| Refusals | `409` with a stable code and a plain-language `detail` telling the admin WHY, plus `blockers: [{type, count, message, ...}]` where useful: `facility_in_use`, `capability_in_use`, `operating_point_in_use`, `facility_has_history`, `*_code_taken`. |
| Audit | Every write appends a hash-chained `audit_log` row in the same transaction (`action` = `config.<area>.<verb>`, `oldValue`/`newValue`, actor, device). Read it with `GET /audit` (section 12). |
| Sync | Every write also writes an outbox `ConfigurationUpdated {domain, changes}` event in the same transaction; `entityVersion` = the aggregate's `rowVersion` after the change, applied version-checked on the other node (stale -> `CONFIGURATION_CONFLICT`, never last-writer-wins). |
| Money | Decimal strings (`"5000.0000"`), never JSON numbers. Times are ISO-8601 UTC unless documented as property-local (`HH:MM`). |
| Deleting | Nothing with history is ever hard-deleted: use deactivate/reactivate (`active: false`). `DELETE` exists only where it can only ever succeed for an unused record and is refused (409) otherwise. |

New permission codes (migration `2026_10_02_100000_config_admin_schema`): `facility.manage`, `config.view`, `config.manage.capabilities`,
`config.manage.rules`, `device.manage`, `ticket_type.manage`, `settings.manage`, `role.manage`. Default bundles: OWNER all; MANAGER `config.view facility.manage
config.manage.capabilities config.manage.rules ticket_type.manage settings.manage`; IT_ADMIN `config.view device.manage`; UNIT_SUPERVISOR and ACCOUNTANT `config.view`.
Existing codes reused: `catalog.manage`, `pricing.manage`, `catalog.availability.manage`, `booking.configure`, `membership.plan.manage`, `staff.manage`,
`role_assignment.manage`, `audit.view`, `device.register`, `device.revoke`, `table.manage`.

## 1. Facilities `[built]`

Aggregate version = `facility_unit.row_version`; capability and operating-rule changes also bump it (one bump per change), so one `If-Match` covers
"the facility and its configuration".

**Facility object** (`Facility`): `id, siteId, parentId, code, name, kind, description, timezone, sortOrder, contact{phone,email,address,managerName}, openingHours,
templateKey, active, status (ACTIVE|INACTIVE), deactivatedAt, deactivationReason, capabilities[], rowVersion`.

`openingHours` = `{ "weekly": { "mon": [{"open":"08:00","close":"22:00"}], ... "sun": [] }, "exceptions": [{"date":"2026-12-25","closed":true,"note":"Christmas"},
{"date":"2026-12-31","closed":false,"windows":[{"open":"10:00","close":"24:00"}]}] }` — property-local times (facility `timezone`, falling back to the site's); a missing/empty weekday = closed;
windows must not overlap; `close` may be `24:00`.

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET /organization/facilities` | any staff | Existing tree (children nested), now includes the new fields, inactive ones with `status: INACTIVE`, ordered by `sortOrder`, creation. |
| `GET /organization/facilities/{id}` | any staff | Existing; `ETag`. |
| `GET /organization/facility-templates` | `config.view|facility.manage` | Templates + valid `kinds`. |
| `GET /organization/capability-types` | `config.view|facility.manage` | Every capability: `code,label,group,description,requires[],ruleKeys[]`. |
| `POST /organization/facilities` | `facility.manage` + Idempotency-Key | Body: `code` (`^[A-Z][A-Z0-9_]{1,63}$`, lower-case is upper-cased, unique per site for ever), `name`, `kind?`, `parentId?`, `description?`, `timezone?` (IANA), `sortOrder?`, `active?`, `contact?`, `openingHours?`, `templateKey?`, `capabilities?` (added to the template's), `operatingRules?` (override the template's), `applyStarter?` (default true: create the template's starter operating points/KDS station). `201` + `Facility` (+ `operatingRules`, `starterOperatingPoints`) + `ETag: "1"`. Errors: 409 `facility_code_taken`, 422 `capability_dependency`, 422 unknown template/rule. |
| `PATCH /organization/facilities/{id}` | `facility.manage` + If-Match | Any of `name, kind, description, timezone, sortOrder, contact, openingHours`. `code`, `parentId`, `active` are rejected (422): use move / deactivate. |
| `POST /organization/facilities/{id}/deactivate` | `facility.manage` + If-Match | Body `{reason?, cascade?}`. Refused with `409 facility_in_use` while it has open orders, open tabs, open cash sessions, upcoming/active bookings, devices checked out to it, or active child facilities (unless `cascade: true`, which then needs every child to be free too). Idempotent. Nothing is deleted; rows stay referenceable. |
| `POST /organization/facilities/{id}/reactivate` | `facility.manage` + If-Match | 409 `parent_inactive` if its parent is inactive. |
| `POST /organization/facilities/{id}/move` | `facility.manage` + If-Match | Body `{parentId: uuid|null}`. Cycle-safe (`422 facility_cycle`), parent must be active; a tree lock serialises concurrent moves. |
| `DELETE /organization/facilities/{id}` | `facility.manage` | Only for a facility nothing references (created by mistake); soft-deletes it (code stays reserved). Otherwise `409 facility_has_history` listing what references it: deactivate instead. |

## 2. Capabilities and operating rules `[built]`

Capabilities are the fixed vocabulary (ADR-0008); rules are typed parameters of them. Both belong to a facility and are version-checked on the facility.

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET /facilities/{id}/capabilities` | any staff | Existing: `{facilityId, capabilities[], operatingRules{camelCase effective values}, version}` — what the apps consume. |
| `PUT /facilities/{id}/capabilities` | `config.manage.capabilities` + If-Match | Body `{capabilities: ["POS","OPEN_TAB",...]}` = the complete enabled set. Response = the effective view above (+ `changed`). Dependencies (see table): enabling something whose prerequisite is not in the set -> `422 capability_dependency` with `errors["capabilities.KITCHEN_ROUTING"] = ["Requires POS to be enabled first."]`; turning off something a remaining capability needs -> same code. Turning a capability off while it is in use -> `409 capability_in_use` + `blockers` (open orders/tabs for POS/TABLE_SERVICE/OPEN_TAB, open cash sessions for PAYMENT_ACCEPTANCE, upcoming bookings for BOOKING/APPOINTMENTS/TIME_SLOTS, stock on hand for INVENTORY, active ticket types for TICKETING). Rules of a disabled capability are kept but ignored; re-enabling restores them. |
| `GET /organization/rule-definitions` | `config.view|config.manage.rules` | The typed catalogue: `key,label,description,group,type,capability,capabilities[],default,unit,allowed[{value,label}],min,max,integer,dangerLevel,enforcement,name`. |
| `GET /facilities/{id}/operating-rules` | `config.view|config.manage.rules` | `{facilityId, version, capabilities[], values{key: value}, items[{key,value,isDefault,label,group,dangerLevel}], notApplicable[keys]}`; only rules whose capability is enabled at the facility are listed. `ETag`. |
| `PUT /facilities/{id}/operating-rules` | `config.manage.rules` + If-Match | Body `{rules: {key: value, ...}, confirm?: bool}`. Partial: only listed keys change; `null` resets a key to its default. Every value is validated against its definition (`422` with `errors["rules.<key>"]`), a key whose capability is not enabled is rejected, and `payment_facility_unit_id` must point at an active facility that accepts payments. Changing any `high` danger rule requires `confirm: true` (`422 danger_confirmation_required`). Response = effective view + `changed[]`. Unchanged values are no-ops (no version bump, no audit). |

Value representation: `bool` -> JSON boolean; `enum` -> string from `allowed`; `multi_enum` -> array of strings from `allowed`; `number` -> JSON number (`integer` flag);
`duration` -> integer in `unit` (seconds/minutes/days); `money` -> decimal string; `facility` -> facility UUID.
`enforcement`: `server` = a server module reads it; `client` = exposed to the apps through `operatingRules` of `GET /facilities/{id}/capabilities` and enforced by them;
`planned` = stored, audited and synced but no runtime consumer yet (show a "not enforced yet" hint).
Booking rules are stored where the Booking module reads them (`booking_rule`, facility scope); `booking_offline_strategy`, `booking_local_reserve_percent`,
`booking_online_stale_after_seconds` are written through to every active bookable resource of the facility (per-resource values can still be edited afterwards, section 9).
The effective `operatingRules` object of `GET /facilities/{id}/capabilities` exposes each key camelCased (`paymentTiming`, `holdTtlSeconds`, ...).

### Rule catalogue (generated from `RuleDefinitions`)

| Key | Type | Unit | Default | Allowed / range | Capability | Danger | Enforced by | What it does |
|---|---|---|---|---|---|---|---|---|
| `payment_timing` | enum |  | PAY_AFTER_SERVICE | PAY_AFTER_SERVICE, PAY_FIRST, OPEN_TAB, PAY_BEFORE_LEAVING, PAY_ON_EXIT, PAY_AT_RECEPTION | POS / OPEN_TAB / TABLE_SERVICE | medium | server | When customers pay: after they are served, before anything is prepared, or on a tab settled on exit/at reception. Existing open orders keep working; new orders follow the new timing. |
| `payment_facility_unit_id` | facility |  | - |  | POS / OPEN_TAB / TABLE_SERVICE / BOOKING / TICKETING / TICKET_VALIDATION | medium | server | For facilities without a payment terminal: the facility where the customer pays (usually Main Reception). It must accept payments. |
| `require_cash_session` | bool |  | true |  | PAYMENT_ACCEPTANCE | high | server | Cash payments can only be taken while the cashier has an open cash-drawer session. Turning this off removes a key cash control. |
| `allow_offline_payments` | enum |  | CASH_ONLY | NONE, CASH_ONLY, ALL | PAYMENT_ACCEPTANCE | high | client | What the apps may accept when the connection to the property server is down. Card and transfer cannot be verified offline. |
| `allow_offline_orders` | bool |  | true |  | POS / TABLE_SERVICE | low | client | Staff may keep taking orders when the connection is down; they sync when it returns. |
| `waiter_collection_enabled` | bool |  | false |  | TABLE_SERVICE / PAYMENT_ACCEPTANCE | high | server | Waiters may collect payment at the table with their tablet (pending until confirmed where required). |
| `waiter_cash_holding` | bool |  | false |  | TABLE_SERVICE / PAYMENT_ACCEPTANCE | high | server | Waiters may keep cash collected at tables until they hand it over. |
| `waiter_cash_in_hand_limit` | money | NGN | 0.0000 |  | TABLE_SERVICE / PAYMENT_ACCEPTANCE | high | server | A waiter must hand over cash once they hold more than this. 0 means no limit. |
| `collection_requires_confirmation` | multi_enum |  | [] | CASH, CARD_TERMINAL, TRANSFER | TABLE_SERVICE / PAYMENT_ACCEPTANCE | high | server | Tender types whose table collections stay pending until a cashier confirms them. |
| `pending_collection_expiry_minutes` | duration | minutes | 30 | 1 .. 10080 | TABLE_SERVICE / PAYMENT_ACCEPTANCE | medium | server | A collection not confirmed within this time expires automatically. |
| `pre_bill_requires_supervisor_if_reopened` | bool |  | true |  | TABLE_SERVICE / PAYMENT_ACCEPTANCE | medium | server | Printing a pre-bill again after a cancelled bill needs supervisor approval. |
| `bill_pay_link_enabled` | bool |  | false |  | TABLE_SERVICE / PAYMENT_ACCEPTANCE | low | server | Print the pay reference / QR code on the pre-bill. |
| `cash_handover_max_variance` | money | NGN | 500.0000 |  | TABLE_SERVICE / PAYMENT_ACCEPTANCE | high | server | A handover whose counted cash differs from expected by more than this needs supervisor sign-off. |
| `approval_threshold_amount` | money | NGN | 0.0000 |  | POS / PAYMENT_ACCEPTANCE | high | server | Amounts above this need a supervisor. 0 means every discount by a non-approver needs approval. |
| `require_approval_for` | multi_enum |  | [] | order.void, order.discount, order.comp, order.price_override, payment.refund, payment.reversal | POS / PAYMENT_ACCEPTANCE | high | server | These actions always need supervisor approval from anyone who is not a supervisor, whatever the amount. |
| `approval_threshold_void_amount` | money | NGN | 0.0000 |  | POS | high | planned | Voids of orders worth more than this need a supervisor. |
| `approval_threshold_comp_amount` | money | NGN | 0.0000 |  | POS | high | planned | Complimentary items worth more than this need a supervisor. |
| `approval_threshold_price_override_amount` | money | NGN | 0.0000 |  | POS | high | planned | Manual price changes larger than this need a supervisor. |
| `approval_threshold_refund_amount` | money | NGN | 0.0000 |  | PAYMENT_ACCEPTANCE | high | planned | Refunds larger than this need a supervisor. |
| `allow_open_tabs` | bool |  | true |  | OPEN_TAB | medium | server | Customers can run a tab that is settled later. |
| `tab_max_amount` | money | NGN | 0.0000 |  | OPEN_TAB | medium | planned | A tab cannot grow beyond this amount without settling. 0 means no limit. |
| `tab_require_customer_name` | bool |  | false |  | OPEN_TAB | low | planned | Staff must type a customer name when opening a tab. |
| `require_table_for_orders` | bool |  | false |  | TABLE_SERVICE | low | client | Every order must be assigned to a dining table. |
| `stock_consumption_timing` | enum |  | SEND | SEND, SETTLE | INVENTORY | high | server | SEND deducts ingredients/stock when the order is sent to the kitchen or bar; SETTLE waits until the customer pays. SEND is safer for stock accuracy. |
| `receipt_auto_print` | bool |  | true |  | RECEIPT_PRINTING | low | client | The app prints a receipt as soon as a payment is taken. |
| `receipt_copies` | number | copies | 1 | 1 .. 5 | RECEIPT_PRINTING | low | client | How many copies the app prints for each receipt. |
| `online_orderable` | bool |  | false |  | POS | medium | planned | Customers can order from this facility on the website/app. |
| `validation_mode` | enum |  | ENTRY | ENTRY, ENTRY_EXIT, RELEASE_RETURN | TICKET_VALIDATION | medium | client | What a scan at this facility means: ENTRY (one entry check), ENTRY_EXIT (in and out are tracked) or RELEASE_RETURN (equipment is released and returned). |
| `max_occupancy` | number | people | - | 1 .. 100000 | CAPACITY_MANAGEMENT | medium | planned | The most people allowed inside at once. Leave unset for no limit. |
| `hold_ttl_seconds` | duration | seconds | 600 | 30 .. 86400 | BOOKING | medium | server | How long a slot is held while the customer completes the booking/payment before it is released. |
| `min_notice_minutes` | duration | minutes | 0 | 0 .. 525600 | BOOKING | low | server | Bookings must be made at least this long before the start time. |
| `max_advance_days` | duration | days | 90 | 0 .. 730 | BOOKING | low | server | How many days ahead customers can book. |
| `cancel_cutoff_minutes` | duration | minutes | 120 | 0 .. 525600 | BOOKING | medium | server | Customers can cancel free until this long before the start. |
| `cancel_fee_percent` | number | percent | 0 | 0 .. 100 | BOOKING | high | server | Percentage of the booking total charged when cancelling inside the free-cancellation window. |
| `reschedule_cutoff_minutes` | duration | minutes | 120 | 0 .. 525600 | BOOKING | low | server | Bookings can be moved until this long before the start. |
| `max_reschedules` | number | times | 2 | 0 .. 20 | BOOKING | low | server | How many times a booking may be moved. |
| `early_entry_minutes` | duration | minutes | 15 | 0 .. 240 | BOOKING | low | server | A booking ticket is valid this long before the slot starts. |
| `slot_granularity_minutes` | number | minutes | 60 | 5 .. 240 | TIME_SLOTS | medium | client | The length of one bookable slot on the grid. |
| `booking_offline_strategy` | enum |  | A_OFFLINE_ALLOCATION | A_OFFLINE_ALLOCATION, B_ONLINE_AUTHORITY_REQUIRED, C_DISABLE_ONLINE | BOOKING | high | server | A: the site keeps taking bookings from a reserved pool of units. B: bookings need the online authority (blocked offline). C: online booking is switched off while the site is offline. Applies to every active bookable resource here. |
| `booking_local_reserve_percent` | number | percent | 0 | 0 .. 100 | BOOKING | high | server | For strategy A: the percentage of each resource capacity the site may allocate while offline (rounded down; 0 = none). |
| `booking_online_stale_after_seconds` | duration | seconds | 900 | 30 .. 604800 | BOOKING | high | server | After this long without a heartbeat from the cloud the site treats itself as offline for booking purposes. |

### Capability dependencies

| Capability | Requires |
|---|---|
| `POS` | - |
| `TABLE_SERVICE` | POS |
| `OPEN_TAB` | POS |
| `KITCHEN_ROUTING` | POS |
| `BAR_ROUTING` | POS |
| `BARCODE_SALES` | POS |
| `PAYMENT_ACCEPTANCE` | - |
| `RECEIPT_PRINTING` | - |
| `TICKETING` | - |
| `TICKET_VALIDATION` | - |
| `QR_VALIDATION` | TICKET_VALIDATION |
| `BOOKING` | - |
| `APPOINTMENTS` | BOOKING |
| `TIME_SLOTS` | BOOKING |
| `CAPACITY_MANAGEMENT` | - |
| `STAFF_ASSIGNMENT` | APPOINTMENTS |
| `INVENTORY` | - |
| `EQUIPMENT_RENTAL` | INVENTORY |
| `MEMBERSHIP` | - |
| `SUBSCRIPTION_BILLING` | MEMBERSHIP |
| `USAGE_LIMITS` | MEMBERSHIP |
| `MEMBER_DISCOUNTS` | MEMBERSHIP |

### Facility templates

| Key | Default kind | Capabilities | Rules | Starter operating points |
|---|---|---|---|---|
| `RESTAURANT` | RESTAURANT | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, TABLE_SERVICE, OPEN_TAB, KITCHEN_ROUTING, INVENTORY | {"approval_threshold_amount":"5000.0000","require_approval_for":["order.void","order.discount","order.comp"],"require_cash_session":true,"payment_timing":"PAY_BEFORE_LEAVING","stock_consumption_timing":"SEND"} | MAIN_DINING (TABLE_AREA), COUNTER_1 (COUNTER) |
| `BAR` | BAR | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, TABLE_SERVICE, OPEN_TAB, BAR_ROUTING, INVENTORY | {"approval_threshold_amount":"5000.0000","require_approval_for":["order.void","order.discount","order.comp"],"require_cash_session":true,"payment_timing":"PAY_BEFORE_LEAVING","stock_consumption_timing":"SEND"} | LOUNGE (TABLE_AREA), COUNTER_1 (COUNTER); KDS BAR_STATION |
| `CLUB` | CLUB | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, TABLE_SERVICE, OPEN_TAB, BAR_ROUTING, INVENTORY, MEMBERSHIP, MEMBER_DISCOUNTS | {"approval_threshold_amount":"5000.0000","require_approval_for":["order.void","order.discount","order.comp"],"require_cash_session":true,"payment_timing":"PAY_ON_EXIT","stock_consumption_timing":"SEND"} | CLUB_FLOOR (TABLE_AREA), COUNTER_1 (COUNTER); KDS BAR_STATION |
| `SPA_SERVICE` | SPA | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, BOOKING, APPOINTMENTS, STAFF_ASSIGNMENT, INVENTORY, MEMBERSHIP, MEMBER_DISCOUNTS, CAPACITY_MANAGEMENT | {"approval_threshold_amount":"5000.0000","require_cash_session":true,"hold_ttl_seconds":900} | COUNTER_1 (COUNTER), ROOMS (ROOM) |
| `SALON` | SALON | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, BOOKING, APPOINTMENTS, STAFF_ASSIGNMENT, INVENTORY, MEMBERSHIP, MEMBER_DISCOUNTS | {"require_cash_session":true,"hold_ttl_seconds":900} | COUNTER_1 (COUNTER) |
| `RETAIL_STORE` | RETAIL | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, BARCODE_SALES, INVENTORY | {"require_cash_session":true,"stock_consumption_timing":"SETTLE"} | TILL_1 (COUNTER) |
| `SPORTS_RESOURCE_GROUP` | SPORTS | BOOKING, TIME_SLOTS, CAPACITY_MANAGEMENT, TICKET_VALIDATION, QR_VALIDATION, MEMBERSHIP | {"slot_granularity_minutes":60,"validation_mode":"ENTRY","hold_ttl_seconds":900} | ENTRANCE (GATE) |
| `POOL` | POOL | TICKET_VALIDATION, QR_VALIDATION, MEMBERSHIP | {"validation_mode":"ENTRY_EXIT"} | ENTRANCE (GATE) |
| `CAFE` | CAFE | POS, PAYMENT_ACCEPTANCE, RECEIPT_PRINTING, INVENTORY | {"require_cash_session":true,"stock_consumption_timing":"SEND","payment_timing":"PAY_FIRST"} | COUNTER_1 (COUNTER) |
| `STORE_ROOM` | STORE | INVENTORY | [] | STORE_WINDOW (STORE_WINDOW) |
| `KITCHEN` | KITCHEN | INVENTORY | {"stock_consumption_timing":"SEND"} | ; KDS PASS |

The waiter-collection rules (`waiter_collection_enabled`, `waiter_cash_holding`, `waiter_cash_in_hand_limit`, `collection_requires_confirmation`, `pending_collection_expiry_minutes`, `pre_bill_requires_supervisor_if_reopened`, `bill_pay_link_enabled`, `cash_handover_max_variance`) are in the catalogue above; behaviour is in `docs/WAITER_COLLECTION.md`. The per-staff `collection-policy` and `payment-terminals` endpoints owned by that module belong on the same admin screens (see that document).

## 3. Operating points, KDS stations `[built]`

`kind`: `TABLE_AREA | COUNTER | GATE | STORE_WINDOW | STATION | ROOM`. A `STATION` can carry a KDS station (same id as the operating point, the convention Hospitality already uses).
Object: `{id, facilityId, code, name, kind, defaultPrepStationId, active, rowVersion, kdsStation: {id, kind (KITCHEN|BAR|DISPENSE), prepRouteId, active} | null}`.

| Endpoint | Permission |
| --- | --- |
| `GET /organization/operating-points?facilityId&kind&includeInactive&limit&cursor` | `config.view|facility.manage` |
| `POST /organization/facilities/{id}/operating-points` (Idempotency-Key) | `facility.manage` — body `{code, name, kind, defaultPrepStationId?, kdsStation?: {kind, prepRouteId?}}` (`kdsStation` only with `kind: STATION`; the prep route defaults to the org's KITCHEN/BAR route, created on first use). 409 `operating_point_code_taken`. |
| `PATCH /organization/operating-points/{id}` (If-Match) | `facility.manage` — `{name?, defaultPrepStationId?, kdsStation?: {prepRouteId}}` |
| `POST /organization/operating-points/{id}/deactivate` / `reactivate` (If-Match) | `facility.manage` — deactivation refused (`409 operating_point_in_use`) while devices are assigned, prep tickets are open on the station or active tables belong to the area. |

## 4. Dining tables `[built]`

Object: `{id, facilityId, operatingPointId (section/area), label, seats, status, active, sortOrder, mergedIntoId, mergedTableIds[], effectiveSeats, rowVersion}`.

| Endpoint | Permission |
| --- | --- |
| `GET /organization/tables?facilityId&operatingPointId&includeInactive` | `config.view|facility.manage|table.manage` |
| `POST /organization/facilities/{id}/tables` (Idempotency-Key) | `facility.manage` — `{label, seats?, operatingPointId?, sortOrder?}`; 409 `table_label_taken`. |
| `POST /organization/facilities/{id}/tables/bulk` (Idempotency-Key) | `facility.manage` — `{prefix:"T", from:1, to:20, seats:4, padWidth?, operatingPointId?}` or `{labels:["A1","A2"], seats}`; max 500; existing labels are skipped and reported: `{created[], skipped[]}`. |
| `PATCH /organization/tables/{id}` (If-Match) | `facility.manage` — `{label?, seats?, operatingPointId?, sortOrder?}` (rename/resize/move section). |
| `POST /organization/tables/{id}/deactivate` / `reactivate` (If-Match) | `facility.manage` — refused while occupied or it has an open order/tab. |
| `POST /organization/tables/{id}/merge` `{intoTableId}` / `POST /organization/tables/{id}/unmerge` | `facility.manage` — joins the seats of two free tables in the same facility; refused while either has an open order/tab. |

Runtime table status changes stay on the existing `PATCH /tables/{id}` (`table.manage`).

## 5. Devices `[built]`

`PATCH /devices/{id}` (`device.manage`, If-Match): `{name?, facilityId?: uuid|null, operatingPointId?: uuid|null, mode?, active?}`. `mode` must fit the device kind (TABLET: ATTENDANT|SUPERVISOR; POS_TERMINAL: POS; KDS_SCREEN: KDS; ENTRANCE_SCANNER: SPORTS_ENTRANCE|SPORTS_STORE;
ATTENDANCE_TERMINAL: ATTENDANCE_TERMINAL); `operatingPointId` must belong to `facilityId` (KDS mode needs a KDS station); moving a device to another facility while it is checked out is refused (`409 device_checked_out`).
Audited (`config.device.update`); devices are node-local hardware, so this change is audited but deliberately not synced to the other node. The device presentation gains `operatingPointId`. Revoke is the existing `POST /devices/{id}/revoke` (`device.revoke`); `GET /devices` is now also open to `device.manage`.

## 6. Catalogue configuration `[built]`

Existing (unchanged): `GET /catalog/categories|products|prep-routes|tax-rates`, `PUT /catalog/products/{id}/availability/{facilityId}` (the "86" switch), `PUT /catalog/products/{id}/price`.
Extended: product create/update accept `description`, `barcode` (unique per org), `modifiers`, and product objects return them.
`modifiers` = `[{ "name":"Doneness", "required":true, "min":1, "max":1, "options":[{"name":"Rare","priceDelta":"0.0000"}, ...] }]` (option names unique per group; `priceDelta` decimal string).

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET /admin/catalog/products?q&categoryId&active&limit&cursor` | `catalog.manage|pricing.manage` | Org-wide admin list (not facility-resolved): sku, name, category, kind, barcode, tax, prep route, active, default price, `facilityCount`, `hasStockLink`. |
| `GET /admin/catalog/products/{id}` | `catalog.manage|pricing.manage` | Full admin view: facilities (availability, station, sortOrder, price override), all price rows, stock links, modifiers. `ETag`. |
| `PUT /catalog/products/{id}/facilities/{facilityId}` (If-Match optional) | `catalog.manage` | `{available, unavailableReason?, kdsStationId?, sortOrder?, price?: money|null}` — makes the product sold here (creates the link), sets station override and a per-facility price override (`null` clears it). `DELETE` (same path) = not sold here. |
| `GET/POST /catalog/price-lists`, `PATCH /catalog/price-lists/{id}` | `pricing.manage` | `{name, currency: NGN, isDefault, active}`; exactly one default. |
| `GET /catalog/prices?productId&priceListId&facilityId&active`, `POST /catalog/prices`, `PATCH /catalog/prices/{id}` | `pricing.manage` | Effective-dated: `{productId, priceListId?, facilityId?, amount, validFrom?, validTo?}`. Overlapping windows for the same product/list/facility -> `409 price_overlap`. A price that is already effective cannot be edited, only end-dated (`validTo`) or superseded by a new one (`409 price_immutable`). |
| `POST /catalog/tax-rates`, `PATCH /catalog/tax-rates/{id}` | `catalog.manage` | `{code, name, ratePercent, active}` (applies to new orders only). `GET` accepts `includeInactive`. |
| `POST /catalog/prep-routes`, `PATCH /catalog/prep-routes/{id}` | `catalog.manage` | `{code, name, kind: KITCHEN|BAR|NONE}` |
| `GET /catalog/prep-route-stations?facilityId`, `PUT /catalog/prep-route-stations` | `catalog.manage` | `{facilityId, prepRouteId, kdsStationId|null}`: orders taken at the facility for that route are prepared at that station (may be in another facility). |
| `POST /catalog/categories/{id}/prep-route` | `catalog.manage` | `{prepRouteId|null, applyToProducts}` applies a prep route to every product of the category. |
| `GET/PUT /catalog/products/{id}/stock-links` | `catalog.manage` | `{links:[{stockItemId, quantityPerUnit}]}` replaces the set (stock items must exist). |
| `GET /catalog/products/export`, `GET /catalog/prices/export` | `catalog.manage` / `pricing.manage` | `text/csv` (UTF-8, header row). |
| `POST /catalog/products/import?dryRun=true`, `POST /catalog/prices/import?dryRun=true` | `catalog.manage` / `pricing.manage` | Body: `text/csv`, or JSON `{csv: "..."}` (max 2 MB / 5000 rows). Upserts by `sku` (products) or `sku`+`facilityCode`+`priceList`+`validFrom` (prices). `dryRun=true` (default) validates and returns the report without writing; `dryRun=false` applies all-or-nothing. No Idempotency-Key needed: importing is idempotent by content (re-uploading the same file reports everything `unchanged`). The dry run executes the real import inside a rolled-back transaction, so it reports exactly what the real run would do (incl. price overlaps). Only the columns present in the file are considered: a missing column leaves the field alone, a present-but-blank cell clears it (`price`, `active`, `trackStock` blank = unchanged); new products need `sku,name,category`. Missing categories are created (reported in `createdCategories`). Report: `{dryRun, totalRows, valid, willCreate, willUpdate, unchanged, createdCategories[], errors[{row, field, message}], applied}`; errors on apply -> `422 import_validation_failed` with the same report. |

Product CSV columns: `sku,name,category,kind,description,barcode,taxRateCode,prepRoute,trackStock,imageUrl,active,price`. Price CSV columns: `sku,facilityCode,priceList,amount,validFrom,validTo`. Cells that start with `= + - @` are exported with a leading apostrophe (spreadsheet formula-injection guard) and un-escaped again on import. Prices: only the default price list is used at checkout; other lists are stored for future use. Creating a price end-dates the previous open-ended price of the same product/list/facility at the new `validFrom`.

Products and prices are versioned rows (`rowVersion`); all catalogue changes above are audited (`config.catalog.*`; the existing `catalog.*` audit actions stay) and emit `ConfigurationUpdated`. Product `PATCH` keeps its existing optional `If-Match` (send it: a stale write gets 412).

## 7. Ticket types `[built]`

`GET /ticketing/ticket-types?facilityId&active` (`config.view|ticket_type.manage|ticket.issue`), `POST /ticketing/ticket-types` (`ticket_type.manage`, Idempotency-Key),
`PATCH /ticketing/ticket-types/{id}` (`ticket_type.manage`, If-Match). Fields: `code, name, facilityId, format (INDIVIDUAL|COMBINED), validationMode (NONE|SINGLE_USE|MULTIPLE_ENTRY|TIME_LIMITED|ENTRY_EXIT|STAFF_APPROVAL),
validityKind (ISSUE_DAY|DURATION_MINUTES|BOOKING_SLOT), validityMinutes (required for DURATION_MINUTES), earlyEntryMinutes, active, productId?, price?`.
`price` (decimal string) creates/updates the linked TICKET product and its default-list price so the ticket is sellable at the facility. The facility must have the TICKETING or TICKET_VALIDATION capability.

## 8. Membership plans `[verified]`

Existing and sufficient: `GET /memberships/plans`, `POST /memberships/plans`, `PATCH /memberships/plans/{id}` (`membership.plan.manage`) including coverage via `facilityIds`/`propertyWide`, discounts and grace/renewal terms.
Gap: plan edits have no `If-Match` (last write wins on the plan row; sold memberships snapshot their terms so money is unaffected).

## 9. Booking configuration `[built]`

Existing: `GET/POST /bookings/resources`, `PATCH /bookings/resources/{id}` (incl. `authority.offlineStrategy A|B|C`, `localReserveUnits`, `onlineStaleAfterSeconds`, `capacity`, `slotMinutes`, price, `active`), `POST /bookings/resources/{id}/blackouts` (`booking.configure`).
Added (all `booking.configure`):
- `GET|PUT /bookings/resources/{id}/schedule` — weekly opening windows `{windows:[{dayOfWeek: 1..7 (Mon..Sun), open:"08:00", close:"22:00", validFrom?, validTo?}]}` (PUT replaces; no windows = property default hours).
- `GET /bookings/resources/{id}/blackouts?from&to`, `DELETE /bookings/blackouts/{id}`, `POST /bookings/blackouts` (facility-wide `{facilityId,start,end,reason}`).
- `GET|PUT /bookings/resources/{id}/rules` — resource-level overrides of the booking rules `{holdTtlSeconds, minNoticeMinutes, maxAdvanceDays, cancelCutoffMinutes, cancelFeePercent, rescheduleCutoffMinutes, maxReschedules, earlyEntryMinutes}` (`null` = inherit the facility rule, then the property default).

## 10. Settings `[built]`

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET/PUT /admin/settings/business` (ETag/If-Match) | `config.view` / `settings.manage` | `{organizationName, siteName, timezone (IANA), currency (NGN, read-only), address, phone, email}`. |
| `GET/PUT /admin/settings/receipt` (ETag/If-Match) | `config.view` / `settings.manage` | `{businessName, address, phone, headerNote, footer, logoUrl, showTin, paperColumns (32|48)}` + read-only `tin`/`vatRegistered` (from `GET /admin/settings/tax`). Receipts read these live (config/env values are the fallback). Reprints keep the receipt that was issued. |
| `GET/PUT /admin/settings/tax` | `config.manage` | Existing (ADR-0011). |
| `GET/PUT /facilities/{id}/payment-methods` (ETag/If-Match = facility version) | `config.view` / `settings.manage` | `{methods: {CASH: true, CARD: false, TRANSFER: true, POS_TERMINAL: true, PAYSTACK: true}}`; no row = all enabled. Enforced by the payment service: a disabled tender is refused with `422 payment_method_disabled`. At least one method must stay enabled at a facility with PAYMENT_ACCEPTANCE. |

## 11. Roles and permissions `[built]`

Existing: `GET /roles`, `GET /permissions`, staff CRUD + credentials (`staff.manage`), role assignments (`role_assignment.manage`, with anti-escalation).
Staff "invitation" = an admin creates the staff member (`POST /staff`) and sets a PIN/password (`PUT /staff/{id}/credentials/...`); there is no e-mailed invitation link yet (gap).

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET /roles/{roleId}/permissions` | `role_assignment.manage|role.manage` | Matrix for the UI: `{role{id,code,name,description,system,editable,rowVersion}, groups:[{group, items:[{code, description, granted, requiresApproval, grantable}]}]}`; `grantable` = the caller holds it. `ETag`. |
| `PUT /roles/{roleId}/permissions` (If-Match) | `role.manage` | `{permissions:[{code, requiresApproval?}]}` = the complete set. Anti-escalation: the caller must hold EVERY permission being added and every permission the role currently has (you cannot edit a role above you); OWNER is immutable (`403 role_immutable`); unknown codes 422. Audited with the added/removed lists; sessions keep working (permissions are read per request). |
| `POST /roles`, `PATCH /roles/{roleId}`, `DELETE /roles/{roleId}` | `role.manage` | Custom roles: `{name, description?, permissions?}` (code derived from the name); `DELETE` only for a custom role with no active assignments (`409 role_in_use`). System roles can be edited (permissions) but not renamed/deleted. |

## 12. Setup progress, search, history `[built]`

`GET /admin/setup-status` (`config.view`): `{percent, complete, steps:[{key,label,done,required,count,hint}], counts{...}, missing:[keys]}` — steps: `business_profile, facilities, products, prices, tax, staff, roles, devices, kds_stations,
payment_methods, receipt_settings, booking_resources, tables`; steps that do not apply to this property (no facility uses that capability) are `required: false` and excluded from `percent`.

`GET /admin/search?q=&types=staff,product,facility,order,receipt,customer&limit=5` (min 2 characters; any staff): grouped `{q, results:{staff:[..], product:[..], ...}}`. Each type is returned only if the caller holds
its permission: staff `staff.manage`; product `catalog.manage|pricing.manage|order.create|inventory.view`; facility `config.view|facility.manage|order.view`; order `order.view` (by order number); receipt `receipt.view` (by number);
customer `membership.view|booking.view`. Items: `{type, id, title, subtitle, ref}`; types the caller may not read are omitted (not an error).

`GET /audit` (existing, `audit.view`; now also `config.view` for configuration entity types only): filters `entityType, entityId, entityTypes (csv), actorStaffId, action (exact) , actionPrefix (e.g. config.facility.), facilityId, from, to (ISO), order=asc|desc`, cursor paging;
rows gain `actorName`. Configuration entity types: `Facility, OperatingPoint, DiningTable, Product, PriceList, Price, TaxRate, PrepRoute, TicketType, Role, Device, ReceiptSetting, BusinessProfile, MembershipPlan, BookableResource`.
Use `entityType=Facility&entityId=<id>&order=desc` for a "change history" panel on any config screen.

## 13. Sync (two nodes)

Every write also writes an outbox event in the same transaction. Domains emitted as `ConfigurationUpdated {domain, changes}` (payload `changes` = camelCase columns / a snapshot; `entityVersion` = the aggregate's `rowVersion` after the change):
`facilityFull` (create, v1: facility + capabilities + rules), `facilityDetails`, `facilityCapabilities`, `facilityRules`, `facilityPaymentMethods` (all versioned on the facility's `row_version`), `operatingPoint` (+ its KDS station), `diningTable`,
`productCategory`, `product`, `productStockLinks`, `productFacility`, `priceList`, `price`, `taxRate`, `prepRoute`, `prepRouteStation`, `ticketType`, `bookableResource`, `resourceSchedule`, `resourceRules`, `blackout`, `receiptSetting`, `businessProfile`.
Role permission sets are emitted as `StaffRosterUpdated {domain: rolePermissions}` (permission conflicts follow the roster rules). Composite-key rows without their own `row_version` (blackout, product-at-facility, prep-route-station) are versioned in `config_entity_version`.
The receiving node (`App\Domain\Config\Sync\ConfigSyncTargets`) applies an event only when `entityVersion == localVersion + 1` (v1 creates the row), defers on a gap, and records a `CONFIGURATION_CONFLICT` for a stale version: never last-writer-wins.
Not synced on purpose: devices (node-local hardware; audited only) and runtime state (table status). `tests/Feature/Config/ConfigSyncTest.php` drives a real local -> cloud drain over two MySQL databases.

## 14. Demo

`php artisan r007:demo-seed` seeds the demo property; log in as `owner1` (PIN `1234`, OWNER at organization scope) to try every endpoint. `manager1` (MANAGER) holds the default manager bundle.

## 15. Known gaps

- `enforcement: planned` rules (approval thresholds per action, tab limits, online-orderable, max occupancy) are stored, audited and synced, but no runtime module enforces them yet (`enforcement: client` rules are enforced by the apps that read `operatingRules`).
- Staff "invitation" is admin-driven (create + set PIN/password); there is no e-mailed invite/reset link yet.
- Membership plan `PATCH` has no `If-Match`.
- Only the default price list is used at checkout.
- The `PAYSTACK` payment method flag is stored but online (Paystack) payments are not gated per facility yet; staff tenders (`CASH`, `CARD`, `TRANSFER`, `POS_TERMINAL`) are enforced.
- Receipt `headerNote`, `logoUrl` and `businessPhone` are in the receipt payload; the pre-rendered `printLines` do not include them yet (clients draw the logo/header).
- Merged tables: the order-taker table list hides merged-away tables, but orders are not yet re-pointed to the parent table by the server.
