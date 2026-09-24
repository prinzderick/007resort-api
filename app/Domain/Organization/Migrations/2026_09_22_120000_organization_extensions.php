<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organization module tables beyond V0001: operating points, bookable resources, organization tax setting (ADR-0011),
 * plus site/facility columns required by the API contract. V0001 stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE site
  ADD COLUMN address VARCHAR(255) NULL,
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'NGN'");
        DB::unprepared("ALTER TABLE site ADD CONSTRAINT ck_site_currency CHECK (currency IN ('NGN'))");
        DB::unprepared("ALTER TABLE facility_unit ADD COLUMN kind VARCHAR(32) NOT NULL DEFAULT 'GENERAL'");

        // Counters, table areas, gates, store windows, KDS stations (a device binds to one).
        DB::unprepared("CREATE TABLE operating_point (
  id                       BINARY(16) NOT NULL PRIMARY KEY,
  organization_id          BINARY(16) NOT NULL,
  site_id                  BINARY(16) NOT NULL,
  facility_unit_id         BINARY(16) NOT NULL,
  code                     VARCHAR(32) NOT NULL,
  name                     VARCHAR(120) NOT NULL,
  kind                     VARCHAR(16) NOT NULL
                             CHECK (kind IN ('TABLE_AREA','COUNTER','GATE','STORE_WINDOW','STATION','ROOM')),
  default_prep_station_id  BINARY(16) NULL,
  is_active                TINYINT(1) NOT NULL DEFAULT 1,
  row_version              INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at               DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_op_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_op_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_op_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_op_code UNIQUE (facility_unit_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

        // Courts, pitches, spa rooms, chairs, PCs: the things Booking allocates slots on (architecture/03 §3.4).
        DB::unprepared('CREATE TABLE bookable_resource (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  code              VARCHAR(32) NOT NULL,
  name              VARCHAR(200) NOT NULL,
  capacity          INT UNSIGNED NOT NULL DEFAULT 1 CHECK (capacity >= 1),
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at        DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_br_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_br_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_br_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_br_code UNIQUE (facility_unit_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');

        // ADR-0011: VAT is an admin-settable organization setting, default OFF. One row per organization.
        DB::unprepared('CREATE TABLE organization_tax_setting (
  organization_id       BINARY(16) NOT NULL PRIMARY KEY,
  vat_registered        TINYINT(1) NOT NULL DEFAULT 0,
  default_vat_rate      DECIMAL(7,4) NOT NULL DEFAULT 7.5000 CHECK (default_vat_rate >= 0 AND default_vat_rate <= 100),
  prices_tax_inclusive  TINYINT(1) NOT NULL DEFAULT 0,
  tin                   VARCHAR(32) NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ots_org FOREIGN KEY (organization_id) REFERENCES organization (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS organization_tax_setting');
        DB::statement('DROP TABLE IF EXISTS bookable_resource');
        DB::statement('DROP TABLE IF EXISTS operating_point');
        DB::statement('ALTER TABLE facility_unit DROP COLUMN kind');
        DB::statement('ALTER TABLE site DROP CONSTRAINT ck_site_currency');
        DB::statement('ALTER TABLE site DROP COLUMN currency, DROP COLUMN address');
    }
};
