<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Prep tickets (KDS). One ticket per (order send, station); items reference the order lines routed there. */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        DB::unprepared("CREATE TABLE prep_ticket_counter (
  station_id  BINARY(16) NOT NULL PRIMARY KEY,
  seq_value   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_ptc_station FOREIGN KEY (station_id) REFERENCES kds_station (id)
) $t");

        DB::unprepared("CREATE TABLE prep_ticket (
  id                BINARY(16) NOT NULL PRIMARY KEY,
  organization_id   BINARY(16) NOT NULL,
  site_id           BINARY(16) NOT NULL,
  facility_unit_id  BINARY(16) NOT NULL,
  station_id        BINARY(16) NOT NULL,
  order_id          BINARY(16) NOT NULL,
  ticket_number     VARCHAR(24) NOT NULL,
  order_number      VARCHAR(40) NOT NULL,
  table_label       VARCHAR(32) NULL,
  waiter_staff_id   BINARY(16) NOT NULL,
  status            VARCHAR(12) NOT NULL DEFAULT 'NEW' CHECK (status IN ('NEW','ACCEPTED','IN_PROGRESS','READY','DISPENSED','CANCELLED')),
  accepted_at       DATETIME(6) NULL,
  accepted_by       BINARY(16) NULL,
  started_at        DATETIME(6) NULL,
  ready_at          DATETIME(6) NULL,
  ready_by          BINARY(16) NULL,
  dispensed_at      DATETIME(6) NULL,
  dispensed_by      BINARY(16) NULL,
  cancelled_at      DATETIME(6) NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pt_org FOREIGN KEY (organization_id) REFERENCES organization (id),
  CONSTRAINT fk_pt_site FOREIGN KEY (site_id) REFERENCES site (id),
  CONSTRAINT fk_pt_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
  CONSTRAINT fk_pt_station FOREIGN KEY (station_id) REFERENCES kds_station (id),
  CONSTRAINT fk_pt_order FOREIGN KEY (order_id) REFERENCES `order` (id),
  CONSTRAINT fk_pt_waiter FOREIGN KEY (waiter_staff_id) REFERENCES staff (id),
  INDEX ix_pt_station (station_id, status, created_at),
  INDEX ix_pt_order (order_id)
) $t");

        DB::unprepared("CREATE TABLE prep_ticket_item (
  id               BINARY(16) NOT NULL PRIMARY KEY,
  prep_ticket_id   BINARY(16) NOT NULL,
  order_line_id    BINARY(16) NOT NULL,
  name             VARCHAR(200) NOT NULL,
  quantity         INT UNSIGNED NOT NULL,
  notes            VARCHAR(255) NULL,
  status           VARCHAR(12) NOT NULL DEFAULT 'NEW' CHECK (status IN ('NEW','ACCEPTED','IN_PROGRESS','READY','DISPENSED','CANCELLED')),
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pti_ticket FOREIGN KEY (prep_ticket_id) REFERENCES prep_ticket (id) ON DELETE CASCADE,
  CONSTRAINT fk_pti_line FOREIGN KEY (order_line_id) REFERENCES order_line (id),
  CONSTRAINT uq_pti_line UNIQUE (order_line_id),
  INDEX ix_pti_ticket (prep_ticket_id)
) $t");
    }

    public function down(): void
    {
        foreach (['prep_ticket_item', 'prep_ticket', 'prep_ticket_counter'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `$t`");
        }
    }
};
