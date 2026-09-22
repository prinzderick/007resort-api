## Summary

<!-- What does this change do and why? Link the issue / ADR. -->

## Type

- [ ] feat
- [ ] fix
- [ ] docs
- [ ] chore / refactor / build / ci

## Checklist

- [ ] Tests added or updated; `dotnet build` and `dotnet test` pass locally
- [ ] Database migrations (if any) reviewed: forward-only, `V{NNNN}__{description}.sql`, no edits to merged migrations, safe on a live site
- [ ] No secrets, credentials, connection strings, keys or `.env` files committed
- [ ] Money uses `decimal` / `DECIMAL(19,4)`; timestamps are UTC; sync-sensitive IDs are UUIDs
- [ ] Public API changes use contracts/DTOs (no entities exposed) and are versioned; OpenAPI still accurate
- [ ] Mutating endpoints accept an idempotency key where applicable
- [ ] Authorization considered (permission checks, facility/operating-point scoping)
- [ ] Sensitive actions are audited; financial records are immutable (reversals only)
- [ ] Logs contain no secrets or personal data beyond what is necessary
- [ ] Docs updated (README / CONTRIBUTING / ADR / module README)
