# Database migrations

**This repository (`007resort-api`) is the single authoritative owner of the 007 Resort & Spa MySQL 8.4 schema.**
No other repository, service or client may create or alter tables.

## Tooling

**Decided (Phase 1): versioned, hand-written SQL scripts applied by [DbUp](https://dbup.readthedocs.io/)**
(`dbup-mysql` provider). See [ADR-0004](../../../007resort-docs/adr/0004-schema-migrations-and-data-access.md)
and `src/R007.Infrastructure/Migrations/DbUpMigrationRunner.cs` for the implementation and the
reasoning (transparent, tracks applied scripts in an auto-managed `SchemaVersions` journal table,
minimal ceremony compared to a bespoke runner or EF Core's generated migrations).

### When migrations run

- **Automatically at API startup** when `ASPNETCORE_ENVIRONMENT=Development`, when
  `R007:DeploymentMode=Site` (a site server self-manages its own schema on deploy), or when
  `R007:Migrations:AutoApply=true` is explicitly set. See `R007.Api/Program.cs` and
  `R007.Api/Configuration/R007Options.cs`.
- **Manually, as a deploy step, everywhere else** (Cloud, staging, production that isn't in Site
  mode): run the separate console runner before starting the API —

  ```bash
  dotnet run --project tools/R007.MigrationRunner -- "<mysql-connection-string>"
  ```

  The connection string comes from the deployment's own secret store — passed as an argument or
  via the `R007_MIGRATIONS_CONNECTION_STRING` environment variable — never committed.

### Deployment-time DB grant lockdown (required, not automated by any migration)

The application's own migration/runtime DB user must never be able to tamper with the audit trail
or other append-only ledgers. After running migrations against a new environment, an operator
with elevated MySQL privileges must run (see `V0001__initial_schema.sql`'s trailing comment):

```sql
REVOKE UPDATE, DELETE ON r007.audit_log FROM 'r007_app'@'%';
```

This cannot be done by the migration user against itself, so it is a manual/scripted deployment
step, not part of `db/migrations`. Track it in your environment's runbook alongside other
commissioning steps (see architecture/16-deployment-topology.md in `007resort-docs`).

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
