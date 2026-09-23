<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sync engine schema (ADR-0013): extends the foundation outbox/inbox/site_health tables and adds the
 * conflict register, the per-entity applied-version ledger and a tiny node-state key/value table.
 * Everything here is MySQL-authoritative; Redis only carries queue jobs (never state).
 */
return new class extends Migration
{
    private const TABLE = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(): void
    {
        // Outbox: a monotonically increasing `seq` gives a total order (per-entity ordering + pull cursor);
        // next_retry_at drives exponential backoff.
        DB::unprepared('ALTER TABLE outbox_event
            ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
            ADD COLUMN next_retry_at DATETIME(6) NULL,
            ADD COLUMN synced_at DATETIME(6) NULL,
            ADD INDEX ix_outbox_due (sync_status, next_retry_at, seq),
            ADD INDEX ix_outbox_entity (entity_type, entity_id, seq)');

        // Inbox: keep the whole envelope so a deferred / failed / conflicting event can be reprocessed
        // without asking the sender again ("never dropped").
        DB::unprepared('ALTER TABLE inbox_event
            ADD COLUMN entity_type VARCHAR(64) NULL,
            ADD COLUMN entity_id BINARY(16) NULL,
            ADD COLUMN entity_version INT UNSIGNED NULL,
            ADD COLUMN organization_id BINARY(16) NULL,
            ADD COLUMN site_id BINARY(16) NULL,
            ADD COLUMN facility_id BINARY(16) NULL,
            ADD COLUMN occurred_at DATETIME(6) NULL,
            ADD COLUMN payload JSON NULL,
            ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN first_deferred_at DATETIME(6) NULL,
            ADD COLUMN next_attempt_at DATETIME(6) NULL,
            ADD COLUMN last_error JSON NULL,
            ADD INDEX ix_inbox_entity (entity_type, entity_id, entity_version),
            ADD INDEX ix_inbox_result (result, next_attempt_at)');

        DB::unprepared('ALTER TABLE site_health
            ADD COLUMN oldest_unsynced_at DATETIME(6) NULL,
            ADD COLUMN last_pulled_cursor VARCHAR(64) NULL,
            ADD COLUMN services JSON NULL');

        // Highest entity_version applied from the peer, per entity (drives out-of-order detection for
        // appliers that opt into EntityOrdered).
        DB::unprepared('CREATE TABLE sync_entity_version (
  entity_type      VARCHAR(64) NOT NULL,
  entity_id        BINARY(16) NOT NULL,
  applied_version  INT UNSIGNED NOT NULL,
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (entity_type, entity_id)
) '.self::TABLE);

        DB::unprepared("CREATE TABLE sync_conflict (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  category          VARCHAR(24) NOT NULL
                      CHECK (category IN ('CONFIGURATION','PERMISSION','BOOKING','INVENTORY','ENTITY_VERSION')),
  status            VARCHAR(16) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN','RESOLVED')),
  event_id          BINARY(16) NULL,
  event_type        VARCHAR(64) NOT NULL,
  entity_type       VARCHAR(64) NOT NULL,
  entity_id         BINARY(16) NOT NULL,
  source_node       VARCHAR(16) NOT NULL,
  local_version     INT UNSIGNED NULL,
  incoming_version  INT UNSIGNED NULL,
  local_payload     JSON NULL,
  incoming_payload  JSON NULL,
  detail            VARCHAR(500) NULL,
  organization_id   BINARY(16) NULL,
  site_id           BINARY(16) NULL,
  detected_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  resolved_at       DATETIME(6) NULL,
  resolved_by_staff_id BINARY(16) NULL,
  resolution        VARCHAR(24) NULL
                      CHECK (resolution IS NULL OR resolution IN ('ACCEPT_INCOMING','KEEP_LOCAL','MANUAL','REPROCESSED','DISMISSED')),
  resolution_note   VARCHAR(1000) NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT uq_sync_conflict_event UNIQUE (event_id),
  INDEX ix_sync_conflict_status (status, category, id),
  INDEX ix_sync_conflict_entity (entity_type, entity_id)
) ".self::TABLE);

        DB::unprepared('CREATE TABLE sync_state (
  k          VARCHAR(64) NOT NULL PRIMARY KEY,
  v          VARCHAR(1000) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) '.self::TABLE);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sync_state');
        DB::statement('DROP TABLE IF EXISTS sync_conflict');
        DB::statement('DROP TABLE IF EXISTS sync_entity_version');
        DB::unprepared('ALTER TABLE site_health DROP COLUMN oldest_unsynced_at, DROP COLUMN last_pulled_cursor, DROP COLUMN services');
        DB::unprepared('ALTER TABLE inbox_event DROP INDEX ix_inbox_entity, DROP INDEX ix_inbox_result,
            DROP COLUMN entity_type, DROP COLUMN entity_id, DROP COLUMN entity_version, DROP COLUMN organization_id,
            DROP COLUMN site_id, DROP COLUMN facility_id, DROP COLUMN occurred_at, DROP COLUMN payload,
            DROP COLUMN attempts, DROP COLUMN first_deferred_at, DROP COLUMN next_attempt_at, DROP COLUMN last_error');
        DB::unprepared('ALTER TABLE outbox_event DROP INDEX ix_outbox_due, DROP INDEX ix_outbox_entity,
            DROP COLUMN seq, DROP COLUMN next_retry_at, DROP COLUMN synced_at');
    }
};
