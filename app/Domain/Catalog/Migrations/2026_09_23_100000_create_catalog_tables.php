<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catalog & pricing schema (architecture/03, 04 §2; ADR-0011 tax setting).
 * product.kind is the commercial kind; the contract's Product.kind (FOOD|DRINK|RETAIL|...) is derived from it + prep route.
 * product_facility: a product is sold at a facility ONLY if a row exists (absence = NOT_SOLD_HERE).
 */
return new class extends Migration
{
    private const T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(): void
    {
        $t = self::T;

        // ADR-0011: org-level VAT setting, admin editable, default OFF.
        DB::unprepared("CREATE TABLE organization_tax_setting (
  organization_id     BINARY(16) NOT NULL PRIMARY KEY,
  vat_enabled         TINYINT(1) NOT NULL DEFAULT 0,
  vat_rate_percent    DECIMAL(7,4) NOT NULL DEFAULT 7.5000 CHECK (vat_rate_percent >= 0 AND vat_rate_percent <= 100),
  prices_tax_inclusive TINYINT(1) NOT NULL DEFAULT 1,
  vat_number          VARCHAR(64) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ots_org FOREIGN KEY (organization_id) REFERENCES organization (id)
) $t");

        DB::unprepared("CREATE TABLE product_category (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  parent_id        BINARY(16) NULL,
  name             VARCHAR(120) NOT NULL,
  sort_order       INT NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pc_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_pc_parent FOREIGN KEY (parent_id) REFERENCES product_category (id),
  INDEX ix_pc_org (organization_id, sort_order)
) $t");

        // Rate table. VAT is applied only when organization_tax_setting.vat_enabled = 1; a row with rate 0 = exempt.
        DB::unprepared("CREATE TABLE tax_rate (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  code             VARCHAR(32) NOT NULL,
  name             VARCHAR(80) NOT NULL,
  rate_percent     DECIMAL(7,4) NOT NULL CHECK (rate_percent >= 0 AND rate_percent <= 100),
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_tr_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT uq_tr_code UNIQUE (organization_id, code)
) $t");

        // Prep route = which kind of preparation a product needs (KITCHEN/BAR/NONE). Mapped to a kds_station per facility.
        DB::unprepared("CREATE TABLE prep_route (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  code             VARCHAR(32) NOT NULL,
  name             VARCHAR(80) NOT NULL,
  kind             VARCHAR(16) NOT NULL CHECK (kind IN ('KITCHEN','BAR','NONE')),
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_prr_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT uq_prr_code UNIQUE (organization_id, code)
) $t");

        DB::unprepared("CREATE TABLE product (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  category_id      BINARY(16) NOT NULL,
  sku              VARCHAR(64) NOT NULL,
  name             VARCHAR(200) NOT NULL,
  kind             VARCHAR(16) NOT NULL DEFAULT 'GOOD'
                     CHECK (kind IN ('GOOD','SERVICE','TICKET','RENTAL','MEMBERSHIP','FEE')),
  tax_rate_id      BINARY(16) NULL,
  tax_exempt       TINYINT(1) NOT NULL DEFAULT 0,
  prep_route_id    BINARY(16) NULL,
  track_stock      TINYINT(1) NOT NULL DEFAULT 0,
  image_url        VARCHAR(500) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at       DATETIME(6) NULL,
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_prod_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES product_category (id),
  CONSTRAINT fk_prod_tax FOREIGN KEY (tax_rate_id) REFERENCES tax_rate (id),
  CONSTRAINT fk_prod_prep FOREIGN KEY (prep_route_id) REFERENCES prep_route (id),
  CONSTRAINT uq_prod_sku UNIQUE (organization_id, sku),
  INDEX ix_prod_cat (category_id),
  INDEX ix_prod_updated (organization_id, updated_at)
) $t");

        DB::unprepared("CREATE TABLE price_list (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  organization_id  BINARY(16) NOT NULL,
  name             VARCHAR(120) NOT NULL,
  currency         CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  is_default       TINYINT(1) NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pl_org FOREIGN KEY (organization_id) REFERENCES organization (id)
) $t");

        // facility_unit_id NULL = list-wide price; a facility row overrides it there. Most specific, latest valid_from wins.
        DB::unprepared("CREATE TABLE price (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  price_list_id     BINARY(16) NOT NULL,
  product_id        BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NULL,
  amount            DECIMAL(19,4) NOT NULL CHECK (amount >= 0),
  valid_from        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  valid_to          DATETIME(6) NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_price_pl FOREIGN KEY (price_list_id) REFERENCES price_list (id),
  CONSTRAINT fk_price_prod FOREIGN KEY (product_id) REFERENCES product (id),
  CONSTRAINT fk_price_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  INDEX ix_price_lookup (product_id, price_list_id, facility_unit_id, is_active)
) $t");

        // kds_station lives in Hospitality (created before this FK is needed by order_line); kds_station_id is a plain
        // BINARY(16) here with an FK added by the Hospitality migration.
        DB::unprepared("CREATE TABLE product_facility (
  product_id         BINARY(16) NOT NULL,
  facility_unit_id   BINARY(16) NOT NULL,
  is_available       TINYINT(1) NOT NULL DEFAULT 1,
  unavailable_reason VARCHAR(24) NULL CHECK (unavailable_reason IS NULL OR unavailable_reason IN ('OUT_OF_STOCK','NOT_SOLD_HERE','INACTIVE','MANUALLY_DISABLED')),
  kds_station_id     BINARY(16) NULL,
  sort_order         INT NOT NULL DEFAULT 0,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,
  created_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (product_id, facility_unit_id),
  CONSTRAINT fk_pf_prod FOREIGN KEY (product_id) REFERENCES product (id),
  CONSTRAINT fk_pf_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  INDEX ix_pf_fac (facility_unit_id)
) $t");

        // Link to Inventory (architecture/09 §55): stock_item_id references Inventory's item table (no FK across modules;
        // Inventory adds its own constraint). quantity_per_unit is the multiplier per product unit sold.
        DB::unprepared("CREATE TABLE product_stock_link (
  id                 BINARY(16) NOT NULL PRIMARY KEY,
  product_id         BINARY(16) NOT NULL,
  stock_item_id      BINARY(16) NOT NULL,
  quantity_per_unit  DECIMAL(19,4) NOT NULL DEFAULT 1.0000 CHECK (quantity_per_unit > 0),
  created_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_psl_prod FOREIGN KEY (product_id) REFERENCES product (id),
  CONSTRAINT uq_psl UNIQUE (product_id, stock_item_id)
) $t");
    }

    public function down(): void
    {
        foreach (['product_stock_link', 'product_facility', 'price', 'price_list', 'product', 'prep_route', 'tax_rate', 'product_category', 'organization_tax_setting'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `$t`");
        }
    }
};
