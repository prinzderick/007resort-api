<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The V0001 `approval` table only records who/status. The approvals workflow (architecture/06, 17 §5; contract
 * Approval schema) needs the action, the target entity, scope, required permission and the stored request payload
 * so a supervisor's decision can apply the action atomically. This ALTER runs before order tables (FKs to approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        $chk = DB::selectOne("SELECT CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval' AND CONSTRAINT_TYPE = 'CHECK'");
        if ($chk) {
            DB::statement("ALTER TABLE approval DROP CHECK `{$chk->n}`");
        }
        DB::unprepared("ALTER TABLE approval
  MODIFY status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  ADD CONSTRAINT ck_approval_status CHECK (status IN ('PENDING','APPROVED','REJECTED','EXPIRED','CANCELLED')),
  ADD COLUMN organization_id     BINARY(16) NULL AFTER id,
  ADD COLUMN site_id             BINARY(16) NULL AFTER organization_id,
  ADD COLUMN facility_unit_id    BINARY(16) NULL AFTER site_id,
  ADD COLUMN action              VARCHAR(64) NULL AFTER facility_unit_id,
  ADD COLUMN entity_type         VARCHAR(48) NULL AFTER action,
  ADD COLUMN entity_id           BINARY(16) NULL AFTER entity_type,
  ADD COLUMN required_permission VARCHAR(96) NULL AFTER entity_id,
  ADD COLUMN amount              DECIMAL(19,4) NULL AFTER required_permission,
  ADD COLUMN summary             VARCHAR(255) NULL AFTER amount,
  ADD COLUMN payload             JSON NULL AFTER summary,
  ADD COLUMN requested_device_id BINARY(16) NULL AFTER payload,
  ADD COLUMN decision_note       VARCHAR(500) NULL AFTER decided_at,
  ADD COLUMN expires_at          DATETIME(6) NULL AFTER decision_note,
  ADD COLUMN row_version         INT UNSIGNED NOT NULL DEFAULT 1 AFTER expires_at,
  ADD INDEX ix_approval_fac_status (facility_unit_id, status, created_at),
  ADD INDEX ix_approval_entity (entity_type, entity_id),
  ADD INDEX ix_approval_requester (requested_by, status)");
    }

    public function down(): void
    {
        //
    }
};
