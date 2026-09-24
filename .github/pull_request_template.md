## Summary

<!-- What does this change do and why? Link the issue / ADR. -->

## Type

- [ ] feat
- [ ] fix
- [ ] docs
- [ ] chore / refactor / build / ci

## Checklist

- [ ] Tests added or updated; `composer lint` and `composer test` pass locally (real MySQL 8.4 + Redis)
- [ ] Migrations (if any) live in the owning module's `Migrations/`, are new files (no edits to merged migrations / V0001), and are safe on a live site
- [ ] No secrets, credentials, keys or `.env` files committed
- [ ] Money is a decimal string / `DECIMAL(19,4)`; timestamps UTC `DATETIME(6)`; ids UUIDv7 `BINARY(16)`
- [ ] Public API changes documented in `docs/openapi/v1.yaml`; errors are `ApiProblem`s with stable codes
- [ ] Mutating endpoints use the `idempotent` middleware where applicable
- [ ] Authorization is permission-based (`permission:<code>`), never role-name-based
- [ ] Sensitive actions call `Audit::record` in the same transaction; cross-node events use `Outbox::record`; financial records immutable
- [ ] Scarce-resource operations have a real concurrent test
- [ ] Logs contain no secrets or personal data beyond what is necessary
- [ ] Docs updated (README / CONTRIBUTING / docs/MODULES.md / ADR)
