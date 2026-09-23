<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** KDS stations (architecture/03 Hospitality). A station serves one prep_route at one facility. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TABLE kds_station (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  prep_route_id     BINARY(16) NULL,
  code              VARCHAR(32) NOT NULL,
  name              VARCHAR(120) NOT NULL,
  kind              VARCHAR(16) NOT NULL CHECK (kind IN ('KITCHEN','BAR','DISPENSE')),
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_kds_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_kds_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_kds_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_kds_route FOREIGN KEY (prep_route_id) REFERENCES prep_route (id),
  CONSTRAINT uq_kds_code UNIQUE (facility_unit_id, code),
  INDEX ix_kds_route (facility_unit_id, prep_route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
        DB::unprepared('ALTER TABLE product_facility ADD CONSTRAINT fk_pf_station FOREIGN KEY (kds_station_id) REFERENCES kds_station (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_facility DROP FOREIGN KEY fk_pf_station');
        DB::statement('DROP TABLE IF EXISTS kds_station');
    }
};
