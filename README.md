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

### Conventions

`/api/v1`, camelCase JSON, ISO-8601 UTC, money as decimal strings, `application/problem+json` errors with stable `code`, cursor pagination
(`?limit=&cursor=` -> `{data, page:{nextCursor,hasMore,limit}}`), `Idempotency-Key` on non-idempotent mutations. Details: [docs/MODULES.md](docs/MODULES.md).

## Configuration

See `.env.example` (placeholders only — never commit real secrets). Key variables: `APP_NODE`, `SITE_ID`, `DB_*`, `REDIS_*`,
`HASH_DRIVER=argon2id`, `IDENTITY_*`. Redis is used for queue/cache/session/rate limits and is **never** authoritative storage.
