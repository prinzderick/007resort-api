# Database migrations

**This repository (`007resort-api`) is the single authoritative owner of the 007 Resort & Spa MySQL 8.4 schema.**
No other repository, service or client may create or alter tables.

## Tooling

Pending ADR review. Candidates:

1. Versioned, hand-written SQL scripts applied by **DbUp** (explicit, reviewable MySQL DDL), or
2. **EF Core migrations** (generated, then reviewed and committed as SQL scripts for deployment).

Until the ADR is accepted, do not add migrations.

## Conventions

- File name: `V{NNNN}__{description}.sql`, e.g. `V0001__create_organization_tables.sql`
  (4-digit zero-padded sequence, double underscore, snake_case description).
- **Forward-only.** No down migrations; fixes are new migrations.
- **Never edit a migration after it is merged to `main`.** It may already have run on a site.
- Every migration is reviewed in a PR (see the PR template checklist).
- Migrations must be safe to run on a live site server: prefer additive changes, backfill in
  separate steps, avoid long table locks (use `ALGORITHM=INPLACE/INSTANT` where possible).
- Follow the data rules in [`src/R007.Infrastructure/README.md`](../../src/R007.Infrastructure/README.md)
  (InnoDB, `DECIMAL(19,4)` money, UTC, `BINARY(16)` UUIDs).
