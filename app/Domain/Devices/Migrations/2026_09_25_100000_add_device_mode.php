<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Explicit device `mode` (which app persona the client runs). Backfilled from the device kind; CHECK-constrained enum. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE device
  ADD COLUMN mode VARCHAR(24) NULL AFTER device_type,
  ADD CONSTRAINT ck_device_mode CHECK (mode IS NULL OR mode IN ('ATTENDANT','SUPERVISOR','SPORTS_ENTRANCE','SPORTS_STORE','POS','KDS','ATTENDANCE_TERMINAL'))");
        DB::unprepared("UPDATE device SET mode = CASE device_type
  WHEN 'TABLET' THEN 'ATTENDANT' WHEN 'POS' THEN 'POS' WHEN 'KDS' THEN 'KDS'
  WHEN 'SCANNER' THEN 'SPORTS_ENTRANCE' WHEN 'BIOMETRIC_TERMINAL' THEN 'ATTENDANCE_TERMINAL' ELSE NULL END");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE device DROP CONSTRAINT ck_device_mode, DROP COLUMN mode');
    }
};
