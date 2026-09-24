<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configuration-management surface (docs/CONFIG_ADMIN_API.md): facility details, device -> operating point, dining-table merge,
 * product description/barcode/modifiers, receipt settings, per-facility payment methods, role metadata, and the new permission codes.
 * V0001 and already-merged migrations stay untouched (additive only).
 */
return new class extends Migration
{
    private const T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /** code => description. OWNER always receives every one of them. */
    private const PERMISSIONS = [
        'facility.manage' => 'Create, edit, deactivate, reactivate and move facilities; manage operating points, KDS stations and dining tables',
        'config.view' => 'View configuration screens: facility templates, capability/rule catalogue, operating rules, setup progress, config history',
        'config.manage.capabilities' => 'Enable or disable facility capabilities',
        'config.manage.rules' => 'Change facility operating rules (payment timing, approval thresholds, booking rules...)',
        'device.manage' => 'Rename devices and change their home facility, operating point and mode',
        'ticket_type.manage' => 'Create and edit ticket types',
        'settings.manage' => 'Edit business profile, receipt settings and payment methods per facility',
        'role.manage' => 'Create and edit roles and their permission sets',
    ];

    private const BUNDLES = [
        'MANAGER' => ['config.view', 'facility.manage', 'config.manage.capabilities', 'config.manage.rules', 'ticket_type.manage', 'settings.manage'],
        'IT_ADMIN' => ['config.view', 'device.manage'],
        'UNIT_SUPERVISOR' => ['config.view'],
        'ACCOUNTANT' => ['config.view'],
    ];

    public function up(): void
    {
        DB::unprepared('ALTER TABLE facility_unit
  ADD COLUMN description         VARCHAR(500) NULL,
  ADD COLUMN timezone            VARCHAR(64) NULL,
  ADD COLUMN sort_order          INT NOT NULL DEFAULT 0,
  ADD COLUMN contact             JSON NULL,
  ADD COLUMN opening_hours       JSON NULL,
  ADD COLUMN template_key        VARCHAR(40) NULL,
  ADD COLUMN deactivated_at      DATETIME(6) NULL,
  ADD COLUMN deactivation_reason VARCHAR(255) NULL');

        DB::unprepared('ALTER TABLE organization ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1');
        DB::unprepared('ALTER TABLE site
  ADD COLUMN phone VARCHAR(40) NULL,
  ADD COLUMN email VARCHAR(190) NULL,
  ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1');

        DB::unprepared('ALTER TABLE dining_table
  ADD COLUMN merge_parent_id BINARY(16) NULL,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0,
  ADD CONSTRAINT fk_dt_merge FOREIGN KEY (merge_parent_id) REFERENCES dining_table (id)');

        DB::unprepared('ALTER TABLE device
  ADD COLUMN operating_point_id BINARY(16) NULL,
  ADD CONSTRAINT fk_device_op FOREIGN KEY (operating_point_id) REFERENCES operating_point (id)');

        DB::unprepared('ALTER TABLE product
  ADD COLUMN description VARCHAR(1000) NULL,
  ADD COLUMN barcode     VARCHAR(64) NULL,
  ADD COLUMN modifiers   JSON NULL,
  ADD CONSTRAINT uq_prod_barcode UNIQUE (organization_id, barcode)');

        foreach (['price_list', 'tax_rate', 'prep_route'] as $t) {
            DB::unprepared("ALTER TABLE {$t} ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1");
        }

        DB::unprepared('CREATE TABLE receipt_setting (
  organization_id  BINARY(16) NOT NULL PRIMARY KEY,
  business_name    VARCHAR(200) NULL,
  address          VARCHAR(255) NULL,
  phone            VARCHAR(40) NULL,
  header_note      VARCHAR(255) NULL,
  footer           VARCHAR(500) NULL,
  logo_url         VARCHAR(500) NULL,
  show_tin         TINYINT(1) NOT NULL DEFAULT 1,
  paper_columns    SMALLINT UNSIGNED NOT NULL DEFAULT 48 CHECK (paper_columns IN (32, 48)),
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_rs_org FOREIGN KEY (organization_id) REFERENCES organization (id)
) '.self::T);

        // Version counter for config entities that have no row_version of their own (composite-key rows such as prep_route_station).
        DB::unprepared('CREATE TABLE config_entity_version (
  entity_id  BINARY(16) NOT NULL PRIMARY KEY,
  version    INT UNSIGNED NOT NULL DEFAULT 0
) '.self::T);

        // No rows for a facility = every method enabled (backward compatible). A row set is the explicit allow-list.
        DB::unprepared("CREATE TABLE facility_payment_method (
  facility_unit_id  BINARY(16) NOT NULL,
  method            VARCHAR(16) NOT NULL CHECK (method IN ('CASH','CARD','TRANSFER','POS_TERMINAL','PAYSTACK')),
  is_enabled        TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (facility_unit_id, method),
  CONSTRAINT fk_fpm_fac FOREIGN KEY (facility_unit_id) REFERENCES facility_unit (id)
) ".self::T);

        DB::unprepared('ALTER TABLE role ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1');
        DB::statement("UPDATE role SET is_system = 1 WHERE code IN ('WAIT_STAFF','BARTENDER','KITCHEN_STAFF','CASHIER','STOREKEEPER','UNIT_SUPERVISOR','PROCUREMENT','ACCOUNTANT','MANAGER','IT_ADMIN','OWNER')");

        foreach (self::PERMISSIONS as $code => $description) {
            DB::statement('INSERT IGNORE INTO permission (code, description) VALUES (?, ?)', [$code, $description]);
        }
        foreach (self::BUNDLES + ['OWNER' => array_keys(self::PERMISSIONS)] as $role => $codes) {
            foreach ($codes as $code) {
                DB::statement(
                    'INSERT IGNORE INTO role_permission (role_id, permission_id) SELECT r.id, p.id FROM role r, permission p WHERE r.code = ? AND p.code = ?',
                    [$role, $code]
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permission')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permission')->whereIn('id', $ids)->delete();
        DB::unprepared('ALTER TABLE role DROP COLUMN is_system, DROP COLUMN row_version');
        foreach (['price_list', 'tax_rate', 'prep_route'] as $t) {
            DB::unprepared("ALTER TABLE {$t} DROP COLUMN row_version");
        }
        DB::statement('DROP TABLE IF EXISTS config_entity_version');
        DB::statement('DROP TABLE IF EXISTS facility_payment_method');
        DB::statement('DROP TABLE IF EXISTS receipt_setting');
        DB::unprepared('ALTER TABLE product DROP INDEX uq_prod_barcode, DROP COLUMN description, DROP COLUMN barcode, DROP COLUMN modifiers');
        DB::unprepared('ALTER TABLE device DROP FOREIGN KEY fk_device_op, DROP COLUMN operating_point_id');
        DB::unprepared('ALTER TABLE dining_table DROP FOREIGN KEY fk_dt_merge, DROP COLUMN merge_parent_id, DROP COLUMN sort_order');
        DB::unprepared('ALTER TABLE site DROP COLUMN phone, DROP COLUMN email, DROP COLUMN row_version');
        DB::unprepared('ALTER TABLE organization DROP COLUMN row_version');
        DB::unprepared('ALTER TABLE facility_unit DROP COLUMN description, DROP COLUMN timezone, DROP COLUMN sort_order, DROP COLUMN contact, DROP COLUMN opening_hours, DROP COLUMN template_key, DROP COLUMN deactivated_at, DROP COLUMN deactivation_reason');
    }
};
