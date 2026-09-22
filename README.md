# otueke-api

Backend API for the **Otueke Integrated Facility Operations Platform** — a multi-facility property
system covering POS, orders, kitchen display (KDS), inventory, bookings, tickets, memberships and
staff authentication, designed to run **local-first on each site with cloud sync**.

> **Status: Phase 0 — scaffolding only.** Project structure, tooling, CI, health and system-info
> endpoints. No business modules, entities or database tables yet; the architecture is under review.

## The API is the brain

- `otueke-api` owns **all business rules** and is the **only** component that talks to MySQL.
- Clients (POS desktop, KDS, admin web, booking web, mobile) are thin: they call the API and render
  what it returns. **Clients must not reimplement business rules** (pricing, tax, permissions,
  stock, entitlements, etc.).
- The same binary runs in two roles, selected by `Otueke:DeploymentMode`:
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
Otueke.sln
src/
  Otueke.Api/             ASP.NET Core host: endpoints, composition root, configuration
  Otueke.Contracts/       Public API DTOs (may be consumed by first-party clients)
  Otueke.SharedKernel/    Minimal shared primitives (IClock, Result/Error)
  Otueke.Infrastructure/  Persistence & integrations (placeholder) — see its README for DB rules
  Modules/                Planned business modules (README only for now)
tests/
  Otueke.UnitTests/         xUnit unit tests
  Otueke.IntegrationTests/  xUnit + WebApplicationFactory smoke tests
db/migrations/            Authoritative schema migrations (tooling pending ADR)
.github/                  CI (build/test + gitleaks secret scan), PR template
```

## Prerequisites

- [.NET 10 SDK](https://dotnet.microsoft.com/download) (pinned via `global.json`, roll-forward to latest feature band)
- MySQL 8.4 LTS (not required yet — no persistence in Phase 0)
- Docker (optional; for running MySQL locally and future Testcontainers-based tests)

## Local setup

Configuration comes from `appsettings.json` → `appsettings.{Environment}.json` → user-secrets
(Development) → environment variables. **Never put real secrets in `appsettings*.json`.**

```bash
# Local secrets (stored outside the repo)
dotnet user-secrets --project src/Otueke.Api set "ConnectionStrings:Otueke" "Server=localhost;Database=otueke;User ID=otueke_app;Password=..."

# or environment variables (see .env.example for the full list)
export ConnectionStrings__Otueke="..."
export Otueke__DeploymentMode=Site
```

## Running

```bash
dotnet run --project src/Otueke.Api --launch-profile http
```

- `GET http://localhost:5080/health/live` — liveness (process up)
- `GET http://localhost:5080/health/ready` — readiness (MySQL check to be added)
- `GET http://localhost:5080/api/v1/system/info` — `{ service, version, environment, mode }`
- `http://localhost:5080/openapi/v1.json` and `http://localhost:5080/scalar/v1` — API docs (**Development only**)

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
| `Otueke:DeploymentMode` | `Otueke__DeploymentMode` | `Site` | `Site` or `Cloud` |
| `ConnectionStrings:Otueke` | `ConnectionStrings__Otueke` | `""` | MySQL 8.4 connection string — secret store only |
| `Serilog:MinimumLevel:Default` | `Serilog__MinimumLevel__Default` | `Information` | Log level |
| `ASPNETCORE_ENVIRONMENT` | — | `Production` | `Development` enables OpenAPI/Scalar UI |
| `ASPNETCORE_URLS` | — | — | e.g. `http://+:5080` |

Signing keys, sync credentials and other secrets are provided via the secret store
(user-secrets locally; the site vault / cloud secret manager when deployed) — never committed.

## Conventions

See [CONTRIBUTING.md](CONTRIBUTING.md) for branching, commits, money/time/ID rules, API and audit
conventions. Architecture decisions and platform-wide docs live in
[prinzderick/otueke-docs](https://github.com/prinzderick/otueke-docs).
