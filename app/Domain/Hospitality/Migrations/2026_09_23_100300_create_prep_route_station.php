<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cross-facility prep routing: orders taken at facility F for prep route R are prepared at station S, which may live in ANOTHER
 * facility (the Restaurant's food is cooked in the Main Kitchen). Checked before the same-facility station lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE prep_route_station (
  facility_unit_id  BINARY(16) NOT NULL,
  prep_route_id     BINARY(16) NOT NULL,
  kds_station_id    BINARY(16) NOT NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (facility_unit_id, prep_route_id),
  CONSTRAINT fk_prs_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_prs_route FOREIGN KEY (prep_route_id) REFERENCES prep_route (id),
  CONSTRAINT fk_prs_station FOREIGN KEY (kds_station_id) REFERENCES kds_station (id),
  INDEX ix_prs_station (kds_station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS prep_route_station');
    }
};
