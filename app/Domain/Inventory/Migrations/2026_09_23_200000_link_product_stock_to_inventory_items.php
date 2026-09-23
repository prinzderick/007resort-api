<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catalog owns `product_stock_link` (product -> stock item x quantity_per_unit) and deliberately has no FK to Inventory's table
 * (module boundary). Inventory adds its own referential constraint here — sorted after both modules' create migrations — so a link
 * can never point at a non-existent item.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('product_stock_link')) {
            return; // Catalog module not installed
        }
        DB::statement('ALTER TABLE product_stock_link ADD CONSTRAINT fk_psl_inventory_item FOREIGN KEY (stock_item_id) REFERENCES inventory_item (id)');
    }

    public function down(): void
    {
        if (DB::getSchemaBuilder()->hasTable('product_stock_link')) {
            DB::statement('ALTER TABLE product_stock_link DROP FOREIGN KEY fk_psl_inventory_item');
        }
    }
};
