<?php

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Port of the verified ASP.NET-era V0001__initial_schema.sql (Organization, Identity, Devices,
 * Audit, idempotency + seeded capability types / roles / permissions / role bundles).
 * The SQL file is applied verbatim (byte-for-byte the verified MySQL 8.4 DDL) — DO NOT EDIT it.
 * Later schema changes go in NEW migrations inside the owning module's Migrations/ directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        SqlFile::run(database_path('sql/V0001__initial_schema.sql'));
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'idempotency_record', 'security_event', 'audit_log', 'approval', 'device_binding', 'device_registration',
            'device', 'session', 'role_assignment', 'role_permission', 'permission', 'role', 'credential',
            'user_account', 'staff', 'operating_rule', 'facility_capability', 'capability_type', 'facility_unit',
            'site', 'organization',
        ] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
