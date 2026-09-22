# 007resort-api

Backend API for the **007 Resort & Spa Integrated Facility Operations Platform** — a multi-facility property
system covering POS, orders, kitchen display (KDS), inventory, bookings, tickets, memberships and
staff authentication, designed to run **local-first on each site with cloud sync**.

> **Status: Phase 1 — core API.** Organization/facility model, staff identity & permission-based
> authorization, device registration & device identity, and the hash-chained audit framework are
> implemented; see `db/migrations/V0001__initial_schema.sql` and `src/Modules/`.

## The API is the brain

- `007resort-api` owns **all business rules** and is the **only** component that talks to MySQL.
- Clients (POS desktop, KDS, admin web, booking web, mobile) are thin: they call the API and render
  what it returns. **Clients must not reimplement business rules** (pricing, tax, permissions,
  stock, entitlements, etc.).
- The same binary runs in two roles, selected by `R007:DeploymentMode`:
  - **Site** — on-premises server at the facility with its own MySQL 8.4; keeps operating when the
    internet is down.
  - **Cloud** — central instance for cross-site sync, reporting and remote administration.

## Architecture summary

- ASP.NET Core (.NET 10) minimal APIs, **modular monolith** (planned modules: `src/Modules/README.md`).
- Versioned routes under `/api/v1` (Asp.Versioning); OpenAPI document per version.
- RFC 7807 `ProblemDetails` for all error responses.
- Structured JSON logging with Serilog (request logging included). Secrets are never logged.
- MySQL 8.4 / InnoDB, schema owned and migrated only from this repo (`db/migrations`).

## Repository layout

```
R007.sln
src/
  R007.Api/             ASP.NET Core host: endpoints, composition root, JWT/authorization wiring
  R007.Contracts/       Public API DTOs, permission codes, shared authorization requirement types
  R007.SharedKernel/    Minimal shared primitives (IClock, Result/Error)
  R007.Infrastructure/  R007DbContext (EF Core, Pomelo/MySQL), DbUp migration runner, Argon2id
                        hashing, JWT issuance, idempotency endpoint filter
  Modules/
    R007.Modules.Organization/  Org/site/facility tree, capabilities, operating rules
    R007.Modules.Identity/      Staff, accounts, credentials, roles/permissions, sessions, auth
    R007.Modules.Devices/       Device registration, device-identity tokens, bindings
    R007.Modules.Audit/         Hash-chained audit log, approvals, security events
tools/
  R007.MigrationRunner/  Standalone `dotnet run` console for manually applying migrations
                         (Cloud/staging/production — see db/migrations/README.md)
tests/
  R007.UnitTests/         xUnit unit tests, NetArchTest module-boundary check
  R007.IntegrationTests/  xUnit + WebApplicationFactory: auth, permissions, devices, audit chain
db/migrations/            Authoritative schema migrations (DbUp, MySQL 8.4 dialect)
.github/                  CI (build/test + gitleaks secret scan), PR template
```

## Prerequisites

- [.NET 10 SDK](https://dotnet.microsoft.com/download) (pinned via `global.json`, roll-forward to latest feature band)
- MySQL 8.4 LTS for a real deployment or full local run (the automated test suite uses an
  in-memory SQLite database instead — see `tests/R007.IntegrationTests/Support/R007WebApplicationFactory.cs`)
- Docker (optional; for running MySQL locally)

## Local setup

Configuration comes from `appsettings.json` → `appsettings.{Environment}.json` → user-secrets
(Development) → environment variables. **Never put real secrets in `appsettings*.json`.**

```bash
# Local secrets (stored outside the repo)
dotnet user-secrets --project src/R007.Api set "ConnectionStrings:R007" "Server=localhost;Database=r007;User ID=r007_app;Password=..."

# or environment variables (see .env.example for the full list)
export ConnectionStrings__R007="..."
export R007__DeploymentMode=Site
```

## Running

```bash
dotnet run --project src/R007.Api --launch-profile http
```

- `GET http://localhost:5080/health/live` — liveness (process up)
- `GET http://localhost:5080/health/ready` — readiness (MySQL check to be added)
- `GET http://localhost:5080/api/v1/system/info` — `{ service, version, environment, mode }`
- `http://localhost:5080/openapi/v1.json` and `http://localhost:5080/scalar/v1` — API docs (**Development only**)

In `Development`, or whenever `R007:DeploymentMode=Site`, pending scripts in `db/migrations` are
applied automatically at startup (DbUp). Everywhere else, run them first:
`dotnet run --project tools/R007.MigrationRunner -- "<connection-string>"` (see
`db/migrations/README.md`).

## Testing

```bash
dotnet build
dotnet test
```

CI (`.github/workflows/ci.yml`) restores, builds in Release and runs all tests on every push/PR to
`main`, and runs a gitleaks secret scan over the full history.

## Configuration

| Key | Env var | Default | Notes |
|---|---|---|---|
| `R007:DeploymentMode` | `R007__DeploymentMode` | `Site` | `Site` or `Cloud` |
| `ConnectionStrings:R007` | `ConnectionStrings__R007` | `""` | MySQL 8.4 connection string — secret store only |
| `R007:Jwt:SigningKey` | `R007__Jwt__SigningKey` | *(none)* | **Required in any real environment.** Symmetric HMAC-SHA256 key, secret store only — never committed. Falls back to a logged development-only placeholder outside `Testing` so `Development` keeps working without a locally-configured secret |
| `R007:Jwt:Issuer` | `R007__Jwt__Issuer` | `https://r007.local` | JWT `iss` claim |
| `R007:Jwt:StaffAudience` | `R007__Jwt__StaffAudience` | `r007-staff` | `aud` claim on staff-session access tokens |
| `R007:Jwt:DeviceAudience` | `R007__Jwt__DeviceAudience` | `r007-device` | `aud` claim on device-identity tokens |
| `R007:Jwt:AccessTokenLifetimeMinutes` | `R007__Jwt__AccessTokenLifetimeMinutes` | `15` | Staff access token lifetime |
| `R007:Jwt:RefreshTokenLifetimeDays` | `R007__Jwt__RefreshTokenLifetimeDays` | `30` | Refresh token (session) lifetime |
| `R007:Jwt:DeviceTokenLifetimeDays` | `R007__Jwt__DeviceTokenLifetimeDays` | `365` | Device-identity token lifetime |
| `R007:Migrations:AutoApply` | `R007__Migrations__AutoApply` | `false` | Force migrations to auto-apply at startup outside Development/Site mode |
| `Serilog:MinimumLevel:Default` | `Serilog__MinimumLevel__Default` | `Information` | Log level |
| `ASPNETCORE_ENVIRONMENT` | — | `Production` | `Development` enables OpenAPI/Scalar UI |
| `ASPNETCORE_URLS` | — | — | e.g. `http://+:5080` |

Signing keys, sync credentials and other secrets are provided via the secret store
(user-secrets locally; the site vault / cloud secret manager when deployed) — never committed.

## Security notes (Phase 1)

- **Passwords/PINs:** Argon2id (`src/R007.Infrastructure/Security/Argon2IdHasher.cs`), parameters
  and rationale documented in that file's XML comments.
- **Authentication:** two independent JWT-based bearer credentials — a short-lived staff-session
  access token (`Authorization: Bearer ...`) plus a rotating, server-revocable refresh token
  (`session` table, hashed), and a long-lived device-identity token issued once at device
  registration. See `src/R007.Infrastructure/Security/JwtTokenService.cs` and
  `src/Modules/R007.Modules.Devices/Authorization/DeviceIdentityRequirement.cs` for the one
  worked example of requiring both on a single endpoint.
- **Authorization:** permission-based, never role-name-based —
  `src/Modules/R007.Modules.Identity/Authorization/PermissionAuthorizationHandler.cs`.
- **Audit:** hash-chained, append-only `audit_log`, written in the same transaction as the
  mutation it records — `src/Modules/R007.Modules.Audit/Application/AuditWriter.cs`.
- **Idempotency:** `Idempotency-Key`-guarded endpoint filter —
  `src/R007.Infrastructure/Idempotency/IdempotencyEndpointFilter.cs` — applied to device
  registration and role-assignment changes.

## Conventions

See [CONTRIBUTING.md](CONTRIBUTING.md) for branching, commits, money/time/ID rules, API and audit
conventions. Architecture decisions and platform-wide docs live in
[prinzderick/007resort-docs](https://github.com/prinzderick/007resort-docs).
