# Membership, Attendance & Reporting (API modules)

Modules: `app/Domain/Membership`, `app/Domain/Attendance`, `app/Domain/Reporting` (+ `customer` / `customer_account` tables in Identity).
Contract of record: `api/openapi/v1.yaml` (Memberships, Attendance, Reports). Endpoints marked **ext** are additive to the contract.
All lists use the `{ items, nextCursor }` envelope; errors are RFC 7807 with stable `code`s; mutating endpoints require `Idempotency-Key`
(exceptions are called out).

## Membership

| Endpoint | Permission | Notes |
| --- | --- | --- |
| `GET /memberships/plans` | any staff | `?active=` |
| `POST /memberships/plans` (**ext**), `PATCH /memberships/plans/{id}` (**ext**) | `membership.plan.manage` | price, duration, `visitLimit` (null = unlimited), `guestAllowance`, `memberDiscountPercent`, `bookingAdvanceDays`/`bookingPrivileges`, `gracePeriodDays`, `renewalNoticeDays`, `propertyWide` or `facilityIds` (coverage includes descendants) |
| `POST /memberships` | `membership.sell` (+ facility scope when `facilityId` given) | plan terms are **snapshotted** on the membership. Tenders equal to the price activate it immediately (see decisions); otherwise `PENDING_PAYMENT` |
| `GET /memberships`, `GET /memberships/{id}`, `/history`, `/usage` | `membership.view` | `?q=` (number/name/phone/email), `filter[status]` |
| `POST /memberships/{id}/renew` (**ext**) | `membership.sell` | needs tenders = current plan price; extends from term end if still running (else from now), resets visits |
| `POST /memberships/{id}/suspend` `/reinstate` `/cancel` (**ext**) | `membership.manage` | reason required |
| `POST /memberships/{id}/cards`, `/cards/{cardId}/revoke` (**ext**) | `membership.manage` | attach an NFC uid; revoke/lost |
| `POST /memberships/validate` | `membership.validate` **at `facilityId`** | body `{facilityId, qrToken \| nfcUid \| membershipNumber, guests?, clientRef?, consume?}` |

Statuses: `PENDING_PAYMENT, ACTIVE, PENDING_RENEWAL, SUSPENDED, EXPIRED, CANCELLED` (allowed transitions in `Membership::TRANSITIONS`;
every change goes through `MembershipLifecycle::transition`: row lock + history + audit + outbox `MembershipStatusChanged`).
Outbox events: `MembershipPurchased`, `MembershipRenewed`, `MembershipStatusChanged`, `MembershipUsageRecorded`.

**Validation** returns `{valid, reason, membership, entitlement{discountPercent, guestsAdmitted, guestAllowance, visitsRemaining, inGrace}, visitNumber, consumed, duplicate}`.
Reasons: `NOT_FOUND, NOT_ACTIVE, SUSPENDED, EXPIRED, WRONG_FACILITY, VISIT_LIMIT_REACHED, GUEST_LIMIT_EXCEEDED`. It consumes a visit unless `consume:false`
(POS discount lookups). The gate is one conditional `UPDATE ... visits_used < visit_limit`, so N concurrent scans on a limit of N admit exactly N.
`clientRef` (unique per membership) makes scanner retries return the original admission instead of double-consuming. Dates are re-checked in SQL,
so a lagging scheduler cannot admit an expired member. Credentials: signed QR token (`M1.<id>.<hmac>`), NFC uid, membership number.

**Scheduler** `r007:membership:lifecycle` (every 10 min, `everyTenMinutes()->withoutOverlapping()`): pending-renewal (ACTIVE, term ends within the plan's notice window),
grace (term ended, `grace_period_days` > 0: stays `PENDING_RENEWAL` with `grace_until`, still admitted, flagged `inGrace`), expire. Idempotent and safe on
both nodes / concurrently (each membership is moved under a row lock with an `expectFrom` guard).

**Payments wiring**: `Listeners\ActivateMembershipOnPaymentCaptured` is subscribed (by string) to `App\Domain\Payments\Events\PaymentCaptured` and calls
`MembershipService::activateOnPayment($membershipId, $paymentId)` for `subjectType = MEMBERSHIP` (idempotent per payment; activates a pending purchase or renews an
active/expired membership). `Services\MembershipPayableSubject::resolve('MEMBERSHIP', $id)` returns `{amountDue, facilityId}` for Payments' `PayableSubjectResolver`
(bind a resolver that delegates to it, next to Booking's).

## Attendance

| Endpoint | Auth | Notes |
| --- | --- | --- |
| `POST /attendance/punches` | `X-Device-Token` of a registered terminal (no Bearer) | contract `PunchIngest` -> `{accepted, duplicates, unmapped}`; `terminalSerial` must match the token's device |
| `GET|POST /iclock/cdata`, `GET /iclock/getrequest`, `POST /iclock/devicecmd` | registered serial (`?SN=`) + optional source allow-list | **ZKTeco ADMS/iClock push**, root paths (not `/api/v1`), `text/plain` |
| `GET /attendance` | `attendance.view` | `filter[staffId|from|to|status]` -> `AttendanceRecord` (+`punchCount`) |
| `GET /attendance/punches` (**ext**) | `attendance.view` | raw punches |
| `POST /attendance/devices` (**ext**) | `attendance.device.manage` | returns `deviceToken` **once** (only its SHA-256 is stored; no Idempotency-Key on purpose so the secret is never persisted in `idempotency_record`), also creates a `device` row (`BIOMETRIC_TERMINAL`). `GET /attendance/devices`, `POST /attendance/devices/{id}/rotate-token`, `POST .../status {ACTIVE|DISABLED}` |
| `GET|POST /attendance/links`, `DELETE /attendance/links/{id}` (**ext**) | `attendance.device.manage` | terminal user id -> staff (per terminal); creating/removing a link re-derives affected days |
| `POST /attendance/corrections`, `GET /attendance/corrections`, `POST .../{id}/approve|reject` (**ext**) | `attendance.correction.request` / `staff.clock_correction.approve` | requester and subject can never decide their own correction (`self_approval_forbidden`) |

Rules: punches are append-only (`UNIQUE (device, terminal user id, punched_at)`; replays count as `duplicates`); staff is resolved at read time through
`staff_biometric_link` (a late link retro-attributes old punches). `attendance_day` is derived per staff and **site-local date**: double taps within
`ATTENDANCE_DEBOUNCE_SECONDS` (120) collapse, punches alternate IN/OUT regardless of the terminal flag, 1 punch = `OPEN`, even = `CLOSED`, odd > 1 = `NEEDS_REVIEW`.
An approved correction sets `source = MANUAL_CORRECTION` and is never overwritten by later biometric rebuilds. Outbox: `StaffClockedIn`, `StaffClockedOut`, `AttendanceCorrected`.
Concurrent batches serialise per staff (locking reads first, so the REPEATABLE READ snapshot is taken after the lock: no lost punches).

Hardware seam: `Contracts\BiometricTerminalAdapter` (`ZktecoAdmsAdapter`, `JsonPushAdapter`, registry `BiometricAdapters::MAP`). Terminals push naive local time;
it is converted with `attendance_device.time_zone` (default = site zone).

### Pointing a ZKTeco terminal at the Local node
Terminal menu -> Comm. -> Cloud Server Setting: Server address = the Local node's LAN IP, port = the API port, HTTPS off (LAN only). Register the terminal's serial
number via `POST /attendance/devices` first (`adapter: ZKTECO_ADMS`), enrol staff on the terminal, then link each terminal user id with `POST /attendance/links`.
`/iclock/*` cannot carry auth headers: restrict it to the terminal VLAN with `ATTENDANCE_ICLOCK_ALLOWED_CIDRS=192.168.10.0/24` (and never expose it on the Cloud node).

Demo: `php artisan db:seed --class='Database\Seeders\AttendanceDemoSeeder'` then `php artisan r007:attendance:simulate --serial=ZK-DEMO-001 --days=5`
(`--url=http://127.0.0.1:8000` sends real ADMS HTTP requests).

## Reporting (read-only)

| Endpoint | Permission |
| --- | --- |
| `GET /reports/facility-daily-summary?date&facilityId` | `report.view` at the facility (includes descendants) |
| `GET /reports/cashier-shift/{shiftId}` | `report.view` at the shift's facility (id = Payments `cash_session` id, or an Orders `shift` id) |
| `GET /reports/revenue?from&to[&facilityId]` (**ext**) | `report.view` at the facility, or `report.view.all` when no facility: by facility, by operating point, by payment method |
| `GET /reports/attendance-summary?from&to[&staffId]` (**ext**) | `attendance.view` |
| `GET /reports/membership-summary?from&to` (**ext**) | `report.view` |

Every response carries `freshness {generatedAt, sourceNode, lastSyncAt, stale, staleReason, ageSeconds, staleAfterSeconds}`. On a Cloud node `stale` is true when the site is
`OFFLINE`, never synced, or `lastSyncAt` is older than `REPORTING_STALE_AFTER_SECONDS` (300); Local is authoritative so it is never stale (`lastSyncAt` = newest SYNCED outbox event).
Sources owned by other modules (`order`, `payment`, `refund`, `reversal`, `cash_session`, `line_void`, ...) are optional: if absent the figures are zero and `availability.<source> = false`.
Definitions: `grossSales = SUM(order.subtotal)`, `discounts`, `tax`, `total` (settled orders in the site-local day), `netSales = total - refunds - reversals`.
SQL views for the admin-web's read-only credential: `v_facility_daily_summary`, `v_payments_by_tender_daily`, `v_cashier_shift_report`, `v_attendance_day_summary`,
`v_membership_status_summary` - created only when their sources exist; run `php artisan r007:reporting:views` after adding modules.

## Environment

`MEMBERSHIP_TRUST_RECEPTION_TENDERS` (true), `MEMBERSHIP_LIFECYCLE_EVERY_MINUTES`, `ATTENDANCE_DEBOUNCE_SECONDS`, `ATTENDANCE_ICLOCK_ALLOWED_CIDRS`, `REPORTING_STALE_AFTER_SECONDS`, `REPORTING_VIEW_UTC_OFFSET` (+01:00).
New permissions (seeded into role bundles by migration): `membership.view|sell|validate|manage|plan.manage`, `attendance.view|device.manage|correction.request`, `report.view`.
