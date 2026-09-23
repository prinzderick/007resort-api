# Contributing — 007resort-api

Read [docs/MODULES.md](docs/MODULES.md) first: it is the how-to for adding a module, the conventions (ids, money, errors, pagination,
audit/outbox, idempotency, permissions) and how to write a real concurrency test. Design docs live in `007resort-docs` (ADRs 0001-0014,
`architecture/*`).

## Non-negotiables

- **Laravel + PHP 8.4-compatible code**, MySQL 8.4, Redis. Business rules live ONLY here.
- **Money** `DECIMAL(19,4)` + currency (NGN); decimal *strings* in PHP/JSON (bcmath via `App\Support\Money\Money`); never float.
  **Time** UTC `DATETIME(6)`. **Ids** UUIDv7 `BINARY(16)`. **Enums** = `VARCHAR` + `CHECK`.
- **Authorization is permission-based, never role-name-based** (`permission:<code>` middleware / `PermissionChecker`).
- Sensitive actions: `Audit::record(...)` in the same transaction. Financial rows are immutable (reversal/refund rows).
- Mutating endpoints take `Idempotency-Key` (`idempotent` middleware). Errors are RFC 7807 `ApiProblem`s with stable `code`s.
- Scarce resources (slots, tickets, stock, payments): DB constraints / conditional updates / row locks **plus a real concurrent test on MySQL**.
- Events that must reach the other node: `Outbox::record(...)` in the same transaction (ADR-0013).
- No secrets in git. `.env.example` holds placeholders only; `.env` is git-ignored. Do not log secrets/tokens/PINs.

## Workflow

1. Branch from the agreed base; Conventional Commits (`feat(orders): ...`).
2. `composer lint` (Pint) and `composer test` must pass. Tests hit real MySQL (`*_test` DB) and Redis — no SQLite.
3. Schema changes = a NEW migration in your module (`app/Domain/<Module>/Migrations/`); never edit merged migrations or `V0001`.
4. Update `docs/openapi/v1.yaml` for API changes; open a PR using the template. CI = PHP 8.4, MySQL 8.4, Redis, migrations, Pint, tests, gitleaks.
