# Contributing to otueke-api

## Workflow

- `main` is protected: all changes arrive via pull request with passing CI and review.
- Branch names: `feature/<short-name>`, `fix/<short-name>`, `docs/<short-name>`, `chore/<short-name>`.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/):
  `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`, `build:`, `ci:` (optionally scoped,
  e.g. `feat(orders): add void with reason`). Breaking changes use `!` / `BREAKING CHANGE:`.
- Fill in the PR template checklist. Keep PRs small and focused.
- The build treats warnings as errors; fix them rather than suppressing them.

## Architecture rules

- **The API is the brain.** Business rules live in the API modules; clients stay thin.
- This repo is the **only owner of the MySQL schema**; see `db/migrations/README.md`.
- Modules communicate through public contracts/integration events, not each other's internals.
- **DTOs never expose entities.** Public request/response types live in `Otueke.Contracts`.
- Breaking API changes require a new API version (`/api/v2`).
- Errors are returned as RFC 7807 `ProblemDetails` with stable error codes.

## Data rules

- **Money:** `decimal` in C#, `DECIMAL(19,4)` in MySQL. **Never `float` or `double`.**
- **Time:** UTC everywhere (`IClock.UtcNow`, `DateTimeOffset`); convert to local time only for display.
- **Identifiers:** UUIDs (UUIDv7 preferred), stored as `BINARY(16)` in MySQL, for any record that is
  synced between site and cloud or created offline.
- **Financial records are immutable:** no updates/deletes of payments, invoices, stock movements or
  ledger entries — corrections are explicit reversal/adjustment records.

## API rules

- Mutating `POST` endpoints accept an **`Idempotency-Key`** header; retries must not duplicate effects.
- Every endpoint declares its authorization requirement (permission + facility/operating-point scope).
- **Every sensitive action is audited** (who, what, when, where/device, before/after, reason) —
  voids, refunds, discounts/price overrides, cash drawer opens, permission changes, stock adjustments,
  login/PIN failures, configuration changes.

## Security

- **No secrets in git** — ever. Use `dotnet user-secrets` locally and environment variables / the
  secret store in deployed environments. `.env` files are git-ignored; only `.env.example`
  (placeholders) is committed. CI runs gitleaks on every push.
- Never log secrets, tokens, PINs, full card data, NFC UIDs in clear or connection strings.
- If a secret is committed by mistake: rotate it immediately, then clean history.

## Testing

- Unit tests for domain/application logic; integration tests through `WebApplicationFactory`.
- Database integration tests will use MySQL 8.4 via Testcontainers (planned).
- `dotnet build` and `dotnet test` must pass before opening a PR.
