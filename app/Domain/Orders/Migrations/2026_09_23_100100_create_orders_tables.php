<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders / tabs / tables / shifts (architecture/03, 04, 08). Money DECIMAL(19,4); lines snapshot name, price, tax and
 * prep route at add-time so later catalog edits never rewrite history. line_adjustment / line_void are append-only.
 * `order` is a reserved word: always backtick it in raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        $ts = "created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),\n  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)";

        DB::unprepared("CREATE TABLE shift (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  staff_id          BINARY(16) NOT NULL,
  device_id         BINARY(16) NULL,
  status            VARCHAR(12) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN','CLOSED')),
  opened_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  closed_at         DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_shift_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_shift_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_shift_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_shift_staff FOREIGN KEY (staff_id) REFERENCES staff (id),
  INDEX ix_shift_staff (staff_id, status)
) $t");

        DB::unprepared("CREATE TABLE dining_table (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  organization_id     BINARY(16) NOT NULL,
  site_id             BINARY(16) NOT NULL,
  facility_unit_id    BINARY(16) NOT NULL,
  operating_point_id  BINARY(16) NULL,
  label               VARCHAR(32) NOT NULL,
  seats               SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  status              VARCHAR(16) NOT NULL DEFAULT 'FREE' CHECK (status IN ('FREE','OCCUPIED','RESERVED','NEEDS_CLEANING')),
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_dt_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_dt_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_dt_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT uq_dt_label UNIQUE (facility_unit_id, label)
) $t");

        DB::unprepared("CREATE TABLE tab (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  dining_table_id   BINARY(16) NULL,
  customer_name     VARCHAR(160) NULL,
  status            VARCHAR(12) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN','SETTLING','SETTLED','VOIDED')),
  currency          CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  opened_by         BINARY(16) NOT NULL,
  opened_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  settled_at        DATETIME(6) NULL,
  client_created_at DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_tab_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_tab_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_tab_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_tab_table FOREIGN KEY (dining_table_id) REFERENCES dining_table (id),
  CONSTRAINT fk_tab_staff FOREIGN KEY (opened_by) REFERENCES staff (id),
  INDEX ix_tab_fac_status (facility_unit_id, status),
  INDEX ix_tab_table (dining_table_id, status)
) $t");

        // Per-facility human order numbers: PREFIX-000123. Atomic via INSERT .. ON DUPLICATE KEY UPDATE LAST_INSERT_ID().
        DB::unprepared("CREATE TABLE order_number_counter (
  facility_unit_id  BINARY(16) NOT NULL PRIMARY KEY,
  seq_value         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_onc_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id)
) $t");

        DB::unprepared("CREATE TABLE `order` (
  id                      BINARY(16) NOT NULL PRIMARY KEY,
  organization_id         BINARY(16) NOT NULL,
  site_id                 BINARY(16) NOT NULL,
  facility_unit_id        BINARY(16) NOT NULL,
  order_number            VARCHAR(40) NOT NULL,
  dining_table_id         BINARY(16) NULL,
  tab_id                  BINARY(16) NULL,
  channel                 VARCHAR(12) NOT NULL DEFAULT 'DINE_IN' CHECK (channel IN ('DINE_IN','TAKEAWAY','ROOM','COUNTER','ONLINE')),
  customer_name           VARCHAR(160) NULL,
  status                  VARCHAR(20) NOT NULL DEFAULT 'DRAFT'
                            CHECK (status IN ('DRAFT','SENT','IN_PREPARATION','READY','SERVED','SETTLED','VOIDED','PENDING_APPROVAL')),
  status_before_approval  VARCHAR(20) NULL,
  currency                CHAR(3) NOT NULL DEFAULT 'NGN' CHECK (currency IN ('NGN')),
  subtotal                DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
  discount_total          DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
  tax_total               DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (tax_total >= 0),
  total                   DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (total >= 0),
  amount_paid             DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (amount_paid >= 0),
  payment_facility_unit_id BINARY(16) NULL,
  created_by              BINARY(16) NOT NULL,
  device_id               BINARY(16) NULL,
  shift_id                BINARY(16) NULL,
  pending_approval_id     BINARY(16) NULL,
  void_reason             VARCHAR(255) NULL,
  client_created_at       DATETIME(6) NULL,
  sent_at                 DATETIME(6) NULL,
  served_at               DATETIME(6) NULL,
  settled_at              DATETIME(6) NULL,
  voided_at               DATETIME(6) NULL,
  row_version             INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_ord_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_ord_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_ord_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_ord_table FOREIGN KEY (dining_table_id) REFERENCES dining_table (id),
  CONSTRAINT fk_ord_tab FOREIGN KEY (tab_id) REFERENCES tab (id),
  CONSTRAINT fk_ord_staff FOREIGN KEY (created_by) REFERENCES staff (id),
  CONSTRAINT fk_ord_shift FOREIGN KEY (shift_id) REFERENCES shift (id),
  CONSTRAINT uq_ord_number UNIQUE (order_number),
  INDEX ix_ord_fac_status (facility_unit_id, status, created_at),
  INDEX ix_ord_table (dining_table_id, status),
  INDEX ix_ord_tab (tab_id),
  INDEX ix_ord_updated (updated_at)
) $t");

        DB::unprepared("CREATE TABLE order_line (
  id                  BINARY(16) NOT NULL PRIMARY KEY,
  order_id            BINARY(16) NOT NULL,
  product_id          BINARY(16) NOT NULL,
  line_no             INT UNSIGNED NOT NULL,
  product_name        VARCHAR(200) NOT NULL,
  sku                 VARCHAR(64) NOT NULL,
  quantity            INT UNSIGNED NOT NULL CHECK (quantity >= 1),
  unit_price          DECIMAL(19,4) NOT NULL CHECK (unit_price >= 0),
  tax_rate_percent    DECIMAL(7,4) NOT NULL DEFAULT 0,
  tax_inclusive       TINYINT(1) NOT NULL DEFAULT 1,
  gross_amount        DECIMAL(19,4) NOT NULL DEFAULT 0,
  discount_amount     DECIMAL(19,4) NOT NULL DEFAULT 0,
  tax_amount          DECIMAL(19,4) NOT NULL DEFAULT 0,
  line_total          DECIMAL(19,4) NOT NULL DEFAULT 0,
  notes               VARCHAR(255) NULL,
  status              VARCHAR(16) NOT NULL DEFAULT 'PENDING'
                        CHECK (status IN ('PENDING','LOCKED','ROUTED','ACCEPTED','IN_PROGRESS','READY','DISPENSED','VOIDED','REMOVED')),
  prep_route_id       BINARY(16) NULL,
  prep_route_kind     VARCHAR(16) NOT NULL DEFAULT 'NONE' CHECK (prep_route_kind IN ('KITCHEN','BAR','NONE')),
  station_id          BINARY(16) NULL,
  client_created_at   DATETIME(6) NULL,
  sent_at             DATETIME(6) NULL,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  $ts,
  CONSTRAINT fk_ol_order FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE CASCADE,
  CONSTRAINT fk_ol_product FOREIGN KEY (product_id) REFERENCES product (id),
  CONSTRAINT fk_ol_route FOREIGN KEY (prep_route_id) REFERENCES prep_route (id),
  CONSTRAINT fk_ol_station FOREIGN KEY (station_id) REFERENCES kds_station (id),
  CONSTRAINT uq_ol_lineno UNIQUE (order_id, line_no),
  INDEX ix_ol_order (order_id, status),
  INDEX ix_ol_product (product_id)
) $t");

        // Append-only layers on top of the immutable line snapshot.
        DB::unprepared("CREATE TABLE line_adjustment (
  id             BINARY(16) NOT NULL PRIMARY KEY,
  order_line_id  BINARY(16) NOT NULL,
  kind           VARCHAR(20) NOT NULL CHECK (kind IN ('DISCOUNT_PERCENT','DISCOUNT_AMOUNT','PRICE_OVERRIDE','COMP')),
  value          VARCHAR(32) NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  amount         DECIMAL(19,4) NOT NULL DEFAULT 0,
  status         VARCHAR(20) NOT NULL DEFAULT 'PENDING_APPROVAL' CHECK (status IN ('PENDING_APPROVAL','APPLIED','REJECTED')),
  approval_id    BINARY(16) NULL,
  requested_by   BINARY(16) NOT NULL,
  applied_by     BINARY(16) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  decided_at     DATETIME(6) NULL,
  CONSTRAINT fk_la_line FOREIGN KEY (order_line_id) REFERENCES order_line (id) ON DELETE CASCADE,
  CONSTRAINT fk_la_approval FOREIGN KEY (approval_id) REFERENCES approval (id),
  CONSTRAINT fk_la_req FOREIGN KEY (requested_by) REFERENCES staff (id),
  INDEX ix_la_line (order_line_id)
) $t");

        DB::unprepared("CREATE TABLE line_void (
  id             BINARY(16) NOT NULL PRIMARY KEY,
  order_id       BINARY(16) NOT NULL,
  order_line_id  BINARY(16) NOT NULL,
  quantity       INT UNSIGNED NOT NULL,
  amount         DECIMAL(19,4) NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  approval_id    BINARY(16) NULL,
  voided_by      BINARY(16) NOT NULL,
  approved_by    BINARY(16) NULL,
  created_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_lv_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  CONSTRAINT fk_lv_line FOREIGN KEY (order_line_id) REFERENCES order_line (id),
  CONSTRAINT fk_lv_approval FOREIGN KEY (approval_id) REFERENCES approval (id),
  CONSTRAINT fk_lv_by FOREIGN KEY (voided_by) REFERENCES staff (id),
  INDEX ix_lv_order (order_id)
) $t");
    }

    public function down(): void
    {
        foreach (['line_void', 'line_adjustment', 'order_line', 'order', 'order_number_counter', 'tab', 'dining_table', 'shift'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `$t`");
        }
    }
};
