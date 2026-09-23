<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** outbox_event / inbox_event / site_health per architecture/sync/outbox-inbox-design.md and heartbeat-and-node-health.md. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TABLE outbox_event (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  event_type      VARCHAR(64) NOT NULL,
  entity_type     VARCHAR(64) NOT NULL,
  entity_id       BINARY(16) NOT NULL,
  entity_version  INT UNSIGNED NOT NULL,
  organization_id BINARY(16) NOT NULL,
  site_id         BINARY(16) NULL,
  facility_id     BINARY(16) NULL,
  payload         JSON NOT NULL,
  sync_status     VARCHAR(16) NOT NULL DEFAULT 'LOCAL'
                    CHECK (sync_status IN ('LOCAL','QUEUED','SYNCING','SYNCED','CONFLICT','FAILED')),
  retry_count     INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME(6) NULL,
  last_error      JSON NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX ix_outbox_status (sync_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

        DB::unprepared("CREATE TABLE inbox_event (
  id              BINARY(16) NOT NULL PRIMARY KEY,
  event_type      VARCHAR(64) NOT NULL,
  source_node     VARCHAR(16) NOT NULL,
  received_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  processed_at    DATETIME(6) NULL,
  result          VARCHAR(16) NOT NULL DEFAULT 'PENDING'
                    CHECK (result IN ('PENDING','APPLIED','CONFLICT','FAILED')),
  conflict_detail JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

        DB::unprepared("CREATE TABLE site_health (
  site_id           BINARY(16) NOT NULL PRIMARY KEY,
  status            VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN'
                      CHECK (status IN ('ONLINE','OFFLINE','UNKNOWN')),
  last_heartbeat_at DATETIME(6) NULL,
  last_sync_at      DATETIME(6) NULL,
  app_version       VARCHAR(32) NULL,
  queue_depth       INT UNSIGNED NULL,
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS site_health');
        DB::statement('DROP TABLE IF EXISTS inbox_event');
        DB::statement('DROP TABLE IF EXISTS outbox_event');
    }
};
