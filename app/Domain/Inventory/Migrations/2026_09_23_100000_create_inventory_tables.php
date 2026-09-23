<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inventory module schema (architecture/09, 04 §3.4). Differences from schema-draft-v0 (called out in the PR):
 *  - quantities are DECIMAL(19,4) (contract: "up to 4 dp"), not (14,3);
 *  - every ledger row also records the counterpart location (source/destination), unit cost, note, the balance after
 *    the movement and an optional `dedupe_key` (UNIQUE) so an order-driven consumption can never be applied twice;
 *  - stock_movement is APPEND-ONLY: BEFORE UPDATE / BEFORE DELETE triggers reject any change (defence in depth;
 *    the deployment grant also REVOKEs UPDATE/DELETE from the app user). Corrections are new movements.
 * `stock_balance` is a guarded projection of the ledger; `r007:inventory:reconcile` checks they agree.
 */
return new class extends Migration
{
    private const TAIL = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(): void
    {
        $t = self::TAIL;

        DB::unprepared("CREATE TABLE supplier (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          name VARCHAR(200) NOT NULL,
          contact_name VARCHAR(200) NULL,
          phone VARCHAR(64) NULL,
          email VARCHAR(200) NULL,
          address VARCHAR(500) NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_supplier_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT uq_supplier_name UNIQUE (organization_id, name)
        ) $t");

        DB::unprepared("CREATE TABLE inventory_item (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          sku VARCHAR(64) NOT NULL,
          name VARCHAR(200) NOT NULL,
          unit VARCHAR(16) NOT NULL DEFAULT 'EACH',
          category VARCHAR(64) NULL,
          reorder_level DECIMAL(19,4) NOT NULL DEFAULT 0 CHECK (reorder_level >= 0),
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_ii_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT uq_item_sku UNIQUE (organization_id, sku),
          INDEX ix_ii_name (organization_id, name)
        ) $t");

        DB::unprepared("CREATE TABLE stock_location (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          site_id BINARY(16) NOT NULL,
          facility_unit_id BINARY(16) NULL,
          name VARCHAR(200) NOT NULL,
          kind VARCHAR(16) NOT NULL DEFAULT 'FACILITY_STORE'
            CHECK (kind IN ('MAIN_STORE','FACILITY_STORE','BAR','KITCHEN','WASTE')),
          allow_negative TINYINT(1) NOT NULL DEFAULT 0,
          is_sale_default TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_sl_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_sl_site FOREIGN KEY (site_id) REFERENCES site (id),
          CONSTRAINT fk_sl_facility FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id),
          CONSTRAINT uq_sl_name UNIQUE (site_id, name),
          INDEX ix_sl_facility (facility_unit_id)
        ) $t");

        DB::unprepared("CREATE TABLE stock_balance (
          item_id BINARY(16) NOT NULL,
          location_id BINARY(16) NOT NULL,
          qty_on_hand DECIMAL(19,4) NOT NULL DEFAULT 0,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          PRIMARY KEY (item_id, location_id),
          CONSTRAINT fk_sb_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          CONSTRAINT fk_sb_location FOREIGN KEY (location_id) REFERENCES stock_location (id),
          INDEX ix_sb_location (location_id)
        ) $t");

        DB::unprepared("CREATE TABLE stock_movement (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          item_id BINARY(16) NOT NULL,
          location_id BINARY(16) NOT NULL,
          counterpart_location_id BINARY(16) NULL,
          qty_delta DECIMAL(19,4) NOT NULL CHECK (qty_delta <> 0),
          balance_after DECIMAL(19,4) NOT NULL,
          reason VARCHAR(24) NOT NULL
            CHECK (reason IN ('RECEIPT','TRANSFER_OUT','TRANSFER_IN','SALE','SALE_RETURN','SUPPLIER_RETURN','CUSTOMER_RETURN',
                              'WASTAGE','ADJUSTMENT','COUNT','RENTAL_OUT','RENTAL_IN')),
          reference_type VARCHAR(32) NOT NULL,
          reference_id BINARY(16) NOT NULL,
          reference_line_id BINARY(16) NULL,
          unit_cost DECIMAL(19,4) NULL,
          note VARCHAR(500) NULL,
          dedupe_key VARCHAR(190) NULL,
          approval_id BINARY(16) NULL,
          actor_staff_id BINARY(16) NULL,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_sm_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_sm_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          CONSTRAINT fk_sm_location FOREIGN KEY (location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_sm_counterpart FOREIGN KEY (counterpart_location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_sm_actor FOREIGN KEY (actor_staff_id) REFERENCES staff (id),
          CONSTRAINT uq_sm_dedupe UNIQUE (dedupe_key),
          INDEX ix_sm_item_location (item_id, location_id, created_at),
          INDEX ix_sm_reference (reference_type, reference_id),
          INDEX ix_sm_ref_line (reference_line_id),
          INDEX ix_sm_location_time (location_id, created_at)
        ) $t");

        DB::unprepared("CREATE TRIGGER trg_stock_movement_no_update BEFORE UPDATE ON stock_movement FOR EACH ROW
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movement is append-only: post a correcting movement instead of updating'");
        DB::unprepared("CREATE TRIGGER trg_stock_movement_no_delete BEFORE DELETE ON stock_movement FOR EACH ROW
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movement is append-only: post a correcting movement instead of deleting'");

        DB::unprepared("CREATE TABLE purchase_receipt (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          location_id BINARY(16) NOT NULL,
          supplier_id BINARY(16) NULL,
          supplier_invoice VARCHAR(100) NULL,
          currency CHAR(3) NOT NULL DEFAULT 'NGN',
          total_cost DECIMAL(19,4) NOT NULL DEFAULT 0,
          note VARCHAR(500) NULL,
          received_by BINARY(16) NULL,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_pr_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_pr_location FOREIGN KEY (location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id),
          CONSTRAINT fk_pr_by FOREIGN KEY (received_by) REFERENCES staff (id),
          INDEX ix_pr_supplier (supplier_id)
        ) $t");

        DB::unprepared("CREATE TABLE purchase_receipt_line (
          id BINARY(16) NOT NULL PRIMARY KEY,
          receipt_id BINARY(16) NOT NULL,
          item_id BINARY(16) NOT NULL,
          quantity DECIMAL(19,4) NOT NULL CHECK (quantity > 0),
          unit_cost DECIMAL(19,4) NULL,
          CONSTRAINT fk_prl_receipt FOREIGN KEY (receipt_id) REFERENCES purchase_receipt (id),
          CONSTRAINT fk_prl_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          INDEX ix_prl_receipt (receipt_id)
        ) $t");

        DB::unprepared("CREATE TABLE stock_transfer (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          from_location_id BINARY(16) NOT NULL,
          to_location_id BINARY(16) NOT NULL,
          status VARCHAR(16) NOT NULL DEFAULT 'POSTED' CHECK (status IN ('POSTED')),
          note VARCHAR(500) NULL,
          created_by BINARY(16) NULL,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_st_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_st_from FOREIGN KEY (from_location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_st_to FOREIGN KEY (to_location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_st_by FOREIGN KEY (created_by) REFERENCES staff (id),
          CONSTRAINT ck_st_distinct CHECK (from_location_id <> to_location_id)
        ) $t");

        DB::unprepared("CREATE TABLE stock_transfer_line (
          id BINARY(16) NOT NULL PRIMARY KEY,
          transfer_id BINARY(16) NOT NULL,
          item_id BINARY(16) NOT NULL,
          quantity DECIMAL(19,4) NOT NULL CHECK (quantity > 0),
          unit_cost DECIMAL(19,4) NULL,
          CONSTRAINT fk_stl_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfer (id),
          CONSTRAINT fk_stl_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          INDEX ix_stl_transfer (transfer_id)
        ) $t");

        // Sensitive manual adjustments and count-variance corrections. A row is the *request*; the ledger
        // movements are written when it is POSTED (immediately for holders of inventory.adjustment.approve,
        // otherwise on approval).
        DB::unprepared("CREATE TABLE stock_adjustment (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          location_id BINARY(16) NOT NULL,
          kind VARCHAR(16) NOT NULL DEFAULT 'ADJUSTMENT' CHECK (kind IN ('ADJUSTMENT','COUNT_VARIANCE')),
          reason VARCHAR(16) NOT NULL CHECK (reason IN ('DAMAGE','THEFT','CORRECTION','EXPIRY','OTHER','COUNT')),
          note VARCHAR(500) NOT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'PENDING_APPROVAL'
            CHECK (status IN ('PENDING_APPROVAL','POSTED','REJECTED','CANCELLED')),
          approval_id BINARY(16) NULL,
          stock_count_id BINARY(16) NULL,
          requested_by BINARY(16) NOT NULL,
          decided_by BINARY(16) NULL,
          decided_at DATETIME(6) NULL,
          decision_note VARCHAR(500) NULL,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_sa_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_sa_location FOREIGN KEY (location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_sa_approval FOREIGN KEY (approval_id) REFERENCES approval (id),
          CONSTRAINT fk_sa_req FOREIGN KEY (requested_by) REFERENCES staff (id),
          CONSTRAINT fk_sa_dec FOREIGN KEY (decided_by) REFERENCES staff (id),
          INDEX ix_sa_status (status, created_at)
        ) $t");

        DB::unprepared("CREATE TABLE stock_adjustment_line (
          id BINARY(16) NOT NULL PRIMARY KEY,
          adjustment_id BINARY(16) NOT NULL,
          item_id BINARY(16) NOT NULL,
          qty_delta DECIMAL(19,4) NOT NULL CHECK (qty_delta <> 0),
          CONSTRAINT fk_sal_adj FOREIGN KEY (adjustment_id) REFERENCES stock_adjustment (id),
          CONSTRAINT fk_sal_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          INDEX ix_sal_adj (adjustment_id)
        ) $t");

        DB::unprepared("CREATE TABLE stock_count (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          location_id BINARY(16) NOT NULL,
          status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT','POSTED')),
          note VARCHAR(500) NULL,
          created_by BINARY(16) NOT NULL,
          posted_by BINARY(16) NULL,
          posted_at DATETIME(6) NULL,
          adjustment_id BINARY(16) NULL,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_sc_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_sc_location FOREIGN KEY (location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_sc_by FOREIGN KEY (created_by) REFERENCES staff (id),
          CONSTRAINT fk_sc_posted FOREIGN KEY (posted_by) REFERENCES staff (id),
          CONSTRAINT fk_sc_adj FOREIGN KEY (adjustment_id) REFERENCES stock_adjustment (id),
          INDEX ix_sc_location (location_id, created_at)
        ) $t");

        DB::unprepared("CREATE TABLE stock_count_line (
          id BINARY(16) NOT NULL PRIMARY KEY,
          count_id BINARY(16) NOT NULL,
          item_id BINARY(16) NOT NULL,
          expected_quantity DECIMAL(19,4) NULL,
          counted_quantity DECIMAL(19,4) NOT NULL CHECK (counted_quantity >= 0),
          variance DECIMAL(19,4) NULL,
          variance_status VARCHAR(20) NULL CHECK (variance_status IN ('NONE','POSTED','PENDING_APPROVAL')),
          CONSTRAINT fk_scl_count FOREIGN KEY (count_id) REFERENCES stock_count (id),
          CONSTRAINT fk_scl_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          CONSTRAINT uq_scl_item UNIQUE (count_id, item_id)
        ) $t");

        // Tagged rentable equipment. Ticketing's release/return goes through RentalService (see Inventory README):
        // issue = conditional UPDATE ... WHERE status='AVAILABLE' so one asset can never be released twice.
        DB::unprepared("CREATE TABLE rental_asset (
          id BINARY(16) NOT NULL PRIMARY KEY,
          organization_id BINARY(16) NOT NULL,
          item_id BINARY(16) NOT NULL,
          location_id BINARY(16) NOT NULL,
          asset_tag VARCHAR(64) NOT NULL,
          status VARCHAR(16) NOT NULL DEFAULT 'AVAILABLE'
            CHECK (status IN ('AVAILABLE','ISSUED','MAINTENANCE','LOST','RETIRED')),
          issued_reference_type VARCHAR(32) NULL,
          issued_reference_id BINARY(16) NULL,
          issued_at DATETIME(6) NULL,
          issued_by BINARY(16) NULL,
          returned_at DATETIME(6) NULL,
          condition_note VARCHAR(500) NULL,
          row_version INT UNSIGNED NOT NULL DEFAULT 1,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          CONSTRAINT fk_ra2_org FOREIGN KEY (organization_id) REFERENCES organization (id),
          CONSTRAINT fk_ra2_item FOREIGN KEY (item_id) REFERENCES inventory_item (id),
          CONSTRAINT fk_ra2_location FOREIGN KEY (location_id) REFERENCES stock_location (id),
          CONSTRAINT fk_ra2_by FOREIGN KEY (issued_by) REFERENCES staff (id),
          CONSTRAINT uq_rental_tag UNIQUE (organization_id, asset_tag),
          INDEX ix_rental_status (location_id, status)
        ) $t");

        $this->permissions();
    }

    /** New permission codes + role bundles (architecture/06). OWNER gets every new code. */
    private function permissions(): void
    {
        $perms = [
            'inventory.view' => 'View items, locations, balances and the stock ledger',
            'inventory.item.manage' => 'Create and edit inventory items',
            'inventory.location.manage' => 'Create and edit stock locations',
            'inventory.wastage.create' => 'Record wastage',
            'inventory.return.create' => 'Record customer/supplier returns',
            'inventory.count.post' => 'Post a physical stock count',
        ];
        foreach ($perms as $code => $desc) {
            DB::table('permission')->insertOrIgnore(['code' => $code, 'description' => $desc]);
        }
        $bundles = [
            'STOREKEEPER' => ['inventory.view', 'inventory.wastage.create', 'inventory.return.create', 'inventory.count.post'],
            'UNIT_SUPERVISOR' => ['inventory.view', 'inventory.wastage.create', 'inventory.return.create', 'inventory.count.post', 'inventory.count.create'],
            'PROCUREMENT' => ['inventory.view', 'inventory.item.manage', 'inventory.return.create'],
            'ACCOUNTANT' => ['inventory.view'],
            'MANAGER' => ['inventory.view', 'inventory.item.manage', 'inventory.location.manage', 'inventory.wastage.create',
                'inventory.return.create', 'inventory.count.post', 'inventory.count.create', 'inventory.transfer.create',
                'inventory.purchase_receipt.create', 'inventory.adjustment.request', 'supplier.manage'],
            'OWNER' => array_keys($perms),
        ];
        foreach ($bundles as $role => $codes) {
            foreach ($codes as $code) {
                DB::insert(
                    'INSERT IGNORE INTO role_permission (role_id, permission_id)
                     SELECT r.id, p.id FROM role r, permission p WHERE r.code = ? AND p.code = ?',
                    [$role, $code],
                );
            }
        }
        // Storekeepers also do the physical receiving at the main store (contract permission).
        DB::insert("INSERT IGNORE INTO role_permission (role_id, permission_id)
                    SELECT r.id, p.id FROM role r, permission p WHERE r.code = 'STOREKEEPER' AND p.code = 'inventory.purchase_receipt.create'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movement_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movement_no_delete');
        foreach ([
            'rental_asset', 'stock_count_line', 'stock_count', 'stock_adjustment_line', 'stock_adjustment',
            'stock_transfer_line', 'stock_transfer', 'purchase_receipt_line', 'purchase_receipt',
            'stock_movement', 'stock_balance', 'stock_location', 'inventory_item', 'supplier',
        ] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS $table");
        }
    }
};
