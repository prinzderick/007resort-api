<?php

use App\Domain\Identity\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * - role.public_id: the contract exposes role ids as UUIDs while V0001's role key is a local BIGINT. Seeded roles get a
 *   DETERMINISTIC UUIDv5 (namespace + code) so both nodes and every client agree on the same id.
 * - role_assignment.active_key: DB-enforced "at most one ACTIVE assignment per (staff, role, scope)" so concurrent /
 *   repeated grants are idempotent by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE role ADD COLUMN public_id BINARY(16) NULL AFTER id');
        foreach (DB::table('role')->get(['id', 'code']) as $r) {
            DB::table('role')->where('id', $r->id)->update(['public_id' => hex2bin(str_replace('-', '', Role::publicIdFor($r->code)))]);
        }
        DB::unprepared('ALTER TABLE role MODIFY public_id BINARY(16) NOT NULL, ADD CONSTRAINT uq_role_public_id UNIQUE (public_id)');

        DB::unprepared("ALTER TABLE role_assignment
  ADD COLUMN active_key CHAR(64) GENERATED ALWAYS AS (
    CASE WHEN is_active = 1 AND deleted_at IS NULL
      THEN SHA2(CONCAT(HEX(staff_id), '|', role_id, '|', scope_level, '|', HEX(COALESCE(facility_unit_id, site_id, organization_id))), 256)
    END) STORED,
  ADD CONSTRAINT uq_ra_active_key UNIQUE (active_key)");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE role_assignment DROP INDEX uq_ra_active_key, DROP COLUMN active_key');
        DB::unprepared('ALTER TABLE role DROP INDEX uq_role_public_id, DROP COLUMN public_id');
    }
};
