# 007resort-api

The single business engine of the 007 Resort & Spa Integrated Facility Operations Platform (ADR-0001, ADR-0012):
**Laravel 13 · PHP 8.4+ · MySQL 8.4 · Redis**, a modular monolith that runs as either the on-premises **Local** node or the **Cloud** node
(`APP_NODE=local|cloud`, ADR-0013). Clients (Flutter mobile, WPF POS, KDS, admin/booking web) only talk to `/api/v1`.

> The former ASP.NET Core implementation is preserved under `legacy/aspnet/` (history intact), on branch
> `feature/phase1-core-api` and tag `pre-laravel-migration-2026-09-22`. It is the behavioural parity reference; do not extend it.

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate      # then edit DB_* / REDIS_* (placeholders only in git)
export PATH="/opt/homebrew/opt/mysql@8.4/bin:$PATH"
mysql -u root -e "CREATE DATABASE r007 CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; CREATE DATABASE r007_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
php artisan migrate
composer dev            # serve (API_PORT, default 8007) + queue + scheduler   ->  http://127.0.0.1:8007/api/v1
composer test           # PHPUnit against REAL MySQL (database name must end in _test) + Redis
composer lint           # Pint (composer fix to auto-format)
```

Tests use `DB_DATABASE=r007_foundation_test` and Redis DB 12/13 by default (`phpunit.xml`); change them for your own scope so parallel developers
don't collide. Dev docs UI (non-production): `GET /api/documentation` (spec: `docs/openapi/v1.yaml`).

## What is in the foundation

| Area | Where |
| --- | --- |
| Module auto-discovery (`app/Domain/*`) | `app/Providers/ModuleServiceProvider.php`, [docs/MODULES.md](docs/MODULES.md) |
| UUIDv7 / `BINARY(16)` cast + `HasUuidV7`, Money (bcmath), RFC 7807 problems, cursor pagination | `app/Support/*` |
| Audit (hash-chained) + `verifyChain()`, transactional Outbox, `idempotent` + `permission:` middleware | `app/Support/*`, `app/Domain/Identity` |
| Schema: verified V0001 (org/identity/devices/audit/idempotency + seeded roles/permissions) + sync tables | `database/sql/V0001__initial_schema.sql`, `app/Domain/*/Migrations` |
| Staff auth: PASSWORD/PIN (Argon2id), opaque access + rotating refresh tokens (hashed, server-side revocation), rate limited | `app/Domain/Identity` |

### Endpoints (foundation)

Follows the contract of record (`007resort-docs/api/openapi/v1.yaml`).
`POST /api/v1/auth/staff/login | refresh | logout | step-up` · `POST /api/v1/auth/sessions/{id}/revoke` · `GET /api/v1/auth/me` (alias `/me`) ·
`GET /api/v1/audit`, `GET /api/v1/audit/verify` · `GET /up`.

Login body: `{"credentialType": "PASSWORD|PIN|NFC_CARD", "identifier": "<username or staff number | card uid>", "secret": "<password | PIN>"}`
(NFC_CARD needs the PIN as secret — never NFC alone). Response = contract `AuthResult` (`accessToken`, `refreshToken`, `expiresInSeconds`, `staff{id,displayName,
staffNumber,roles,permissions,facilityIds}`, `session{id,expiresAt,deviceId}`). Send `Authorization: Bearer <accessToken>`; refresh with
`{"refreshToken": "..."}` on `token_expired`. 5 failed logins lock an account for 15 minutes (403 `account_locked`). Supervisor step-up returns a single-use `X-Step-Up-Token`.

`GET /api/v1/system/info` · `GET /up`, `/health/live`, `/health/ready`, `/api/v1/system/health` (DB + Redis; 503 when down) ·
`GET /api/v1/organization/site | facilities | facilities/{id} | facilities/{id}/operating-points` · `GET /api/v1/facilities/{id}/capabilities` ·
`GET|PUT /api/v1/admin/settings/tax` (ADR-0011, default VAT off; `config.manage`, `If-Match`) ·
`GET|POST /api/v1/staff`, `GET|PATCH /api/v1/staff/{id}`, `PUT /api/v1/staff/{id}/credentials/password|pin|nfc-card`, `DELETE .../nfc-card` ·
`GET /api/v1/roles`, `GET /api/v1/permissions`, `GET|POST /api/v1/staff/{id}/role-assignments`, `DELETE .../{assignmentId}` ·
`POST /api/v1/devices/register`, `POST /api/v1/devices/registration-codes`, `GET /api/v1/devices`, `GET /api/v1/devices/{id}`,
`POST /api/v1/devices/{id}/checkout | checkin | status | revoke`, `GET /api/v1/devices/{id}/commands` · `POST /api/v1/broadcasting/auth`.

### Run a Local node (dev)

```bash
php artisan migrate && php artisan r007:demo-seed     # DEV ONLY demo property + staff + devices (refuses in production)
# put SITE_ID=f0b11797-fc39-5fb4-badf-fd98c2aeeeaa in .env (the demo site id, printed by the seeder)
composer local-node       # serve (API_PORT, default 8007) + queue worker + scheduler + Reverb (8081)
composer dev              # same without Reverb
```

Ports used by this repo: API `8007`, Reverb `8081` (contract), MySQL `3306` (`r007_foundation`, tests `r007_foundation_test`), Redis `6379`
(DB `10`/`11` dev, `12`/`13` tests, key prefix `r007_foundation_`). Use your own database/Redis DB/ports when working in parallel.

#### DEV-ONLY demo credentials (`php artisan r007:demo-seed`)

Login body: `{"credentialType":"PIN","identifier":"wait1","secret":"1234"}` (identifier = username **or** staff number like `S-0001`; `PASSWORD` works too).
Every demo user has **PIN `1234`** and **password `Dev#Pass1234`**. NEVER used in production (the seeder refuses).

| username | role | scope |
| --- | --- | --- |
| `wait1` (S-0001) | WAIT_STAFF | Restaurant, Indoor Club |
| `wait2` (S-0002) | WAIT_STAFF | Pool Bar, Bush Bar, Event Centre |
| `bartender1` | BARTENDER | Pool Bar, Bush Bar, Indoor Club |
| `kitchen1` | KITCHEN_STAFF | Main Kitchen, Restaurant |
| `cashier1` (S-0005) | CASHIER | Main Reception |
| `cashier2` | CASHIER | Restaurant, Cafe, Supermarket, Super Store |
| `storekeeper1` | STOREKEEPER | Main Store, Sports Store, Supermarket |
| `supervisor1` | UNIT_SUPERVISOR | whole site |
| `procurement1`, `accountant1`, `manager1`, `itadmin1` | PROCUREMENT / ACCOUNTANT / MANAGER / IT_ADMIN | whole site |
| `owner1` | OWNER | organization |

Demo devices have deterministic tokens `r7d_dev_<code lower-case>` (send as `X-Device-Token`), e.g. `r7d_dev_pos_reception_1`, `r7d_dev_kds_main_kitchen`,
`r7d_dev_tablet_waiter_01`..`12`, `r7d_dev_tablet_supervisor_1`..`4`, `r7d_dev_tablet_sports_entrance`, `r7d_dev_attendance_main_gate` (the seeder prints them all).
Ids of demo facilities/staff/devices are deterministic UUIDv5 (`App\Support\Demo\DemoIds`). New device outside the demo: `php artisan r007:device-code --facility=RESTAURANT`,
then `POST /api/v1/devices/register`. Modules add their own demo data by shipping `app/Domain/<Module>/Demo/*Seeder.php` (see `App\Support\Demo\DemoSeeder`).

### Conventions

`/api/v1`, camelCase JSON, ISO-8601 UTC, money as decimal strings, `application/problem+json` errors with stable `code`, cursor pagination
(`?limit=&cursor=` -> `{data, page:{nextCursor,hasMore,limit}}`), `Idempotency-Key` on non-idempotent mutations. Details: [docs/MODULES.md](docs/MODULES.md).

## Configuration

See `.env.example` (placeholders only — never commit real secrets). Key variables: `APP_NODE`, `SITE_ID`, `DB_*`, `REDIS_*`,
`HASH_DRIVER=argon2id`, `IDENTITY_*`. Redis is used for queue/cache/session/rate limits and is **never** authoritative storage.
