<?php

namespace App\Domain\Inventory\Database;

use App\Domain\Inventory\Services\StockDocuments;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Demo inventory: Main Store + one sub-store per operating facility, ~60 everyday bar / restaurant / supermarket / salon /
 * sports goods, opening stock received into the Main Store via a real purchase receipt and distributed to the facility
 * stores via real transfers (so the ledger, balances and audit trail are genuine). Idempotent: does nothing to stock if the
 * organization already has ledger movements. Run standalone with `php artisan r007:inventory:demo-seed`; it also runs
 * automatically after `php artisan r007:demo-seed`.
 */
class InventoryDemoSeeder
{
    /** location key => [name, kind, facility code|null, facility name] */
    private const LOCATIONS = [
        'MAIN' => ['Main Store', 'MAIN_STORE', null, null],
        'RST' => ['Restaurant Store', 'FACILITY_STORE', 'restaurant', 'Restaurant'],
        'KIT' => ['Main Kitchen Store', 'KITCHEN', 'main-kitchen', 'Main Kitchen'],
        'BAR' => ['Bush Bar Store', 'BAR', 'bush-bar', 'Bush Bar'],
        'SAL' => ['Salon Store', 'FACILITY_STORE', 'salon', 'Salon'],
        'SUP' => ['Supermarket Shelf', 'FACILITY_STORE', 'supermarket', 'Supermarket'],
        'SPT' => ['Sports Store', 'FACILITY_STORE', 'sports-store', 'Sports Store'],
        'WST' => ['Waste / Write-off', 'WASTE', null, null],
    ];

    /**
     * sku, name, unit, category, reorder level, Main Store opening qty, unit cost (NGN), [location key => qty transferred out].
     */
    private const ITEMS = [
        // Beverages - bar
        ['BEV-STAR-60', 'Star Lager Beer 60cl', 'bottle', 'Beer', '48', '600', '650', ['BAR' => '240', 'RST' => '96', 'SUP' => '120']],
        ['BEV-GUIN-60', 'Guinness Stout 60cl', 'bottle', 'Beer', '48', '480', '750', ['BAR' => '192', 'RST' => '72', 'SUP' => '96']],
        ['BEV-HERO-60', 'Heineken 60cl', 'bottle', 'Beer', '48', '480', '800', ['BAR' => '192', 'RST' => '72', 'SUP' => '96']],
        ['BEV-TROP-50', 'Trophy Lager 50cl', 'bottle', 'Beer', '48', '360', '500', ['BAR' => '144', 'SUP' => '96']],
        ['BEV-HENN-70', 'Hennessy VS 70cl', 'bottle', 'Spirits', '6', '36', '38000', ['BAR' => '12', 'RST' => '6']],
        ['BEV-JACK-75', 'Jack Daniel\'s 75cl', 'bottle', 'Spirits', '6', '36', '28000', ['BAR' => '12', 'SUP' => '6']],
        ['BEV-JOHN-75', 'Johnnie Walker Black 75cl', 'bottle', 'Spirits', '6', '30', '32000', ['BAR' => '12', 'RST' => '6']],
        ['BEV-SMIR-75', 'Smirnoff Vodka 75cl', 'bottle', 'Spirits', '6', '48', '9500', ['BAR' => '18', 'SUP' => '12']],
        ['BEV-GORD-75', 'Gordon\'s Dry Gin 75cl', 'bottle', 'Spirits', '6', '36', '9000', ['BAR' => '12']],
        ['BEV-BACA-75', 'Bacardi White Rum 75cl', 'bottle', 'Spirits', '6', '30', '9500', ['BAR' => '12']],
        ['BEV-CHAM-75', 'Chamdor Sparkling Wine 75cl', 'bottle', 'Wine', '6', '60', '4200', ['BAR' => '18', 'RST' => '12', 'SUP' => '12']],
        ['BEV-REDW-75', 'Red Wine House 75cl', 'bottle', 'Wine', '6', '48', '6500', ['RST' => '18', 'BAR' => '12']],
        ['BEV-WHTW-75', 'White Wine House 75cl', 'bottle', 'Wine', '6', '48', '6500', ['RST' => '18', 'BAR' => '12']],
        // Soft drinks & water
        ['SFT-COKE-50', 'Coca-Cola 50cl', 'bottle', 'Soft Drinks', '48', '960', '200', ['BAR' => '288', 'RST' => '144', 'SUP' => '240', 'SPT' => '96']],
        ['SFT-FANT-50', 'Fanta Orange 50cl', 'bottle', 'Soft Drinks', '48', '720', '200', ['BAR' => '192', 'RST' => '96', 'SUP' => '192', 'SPT' => '48']],
        ['SFT-SPRT-50', 'Sprite 50cl', 'bottle', 'Soft Drinks', '48', '720', '200', ['BAR' => '192', 'RST' => '96', 'SUP' => '192', 'SPT' => '48']],
        ['SFT-WATR-75', 'Bottled Water 75cl', 'bottle', 'Water', '96', '1200', '120', ['BAR' => '240', 'RST' => '192', 'SUP' => '360', 'SPT' => '192', 'SAL' => '48']],
        ['SFT-TONI-20', 'Tonic Water 20cl', 'bottle', 'Mixers', '24', '240', '250', ['BAR' => '96', 'RST' => '48']],
        ['SFT-ORJC-1L', 'Orange Juice 1L', 'carton', 'Juice', '12', '120', '1400', ['BAR' => '36', 'RST' => '24', 'SUP' => '36']],
        ['SFT-PINE-1L', 'Pineapple Juice 1L', 'carton', 'Juice', '12', '120', '1400', ['BAR' => '36', 'RST' => '24', 'SUP' => '36']],
        ['SFT-REDB-25', 'Red Bull 25cl', 'can', 'Energy Drinks', '24', '240', '900', ['BAR' => '72', 'SUP' => '96', 'SPT' => '24']],
        // Kitchen / restaurant ingredients
        ['KIT-RICE-50', 'Long Grain Rice 50kg', 'bag', 'Grains', '2', '30', '68000', ['KIT' => '10']],
        ['KIT-BEAN-25', 'Brown Beans 25kg', 'bag', 'Grains', '1', '12', '42000', ['KIT' => '4']],
        ['KIT-YAMS-EA', 'Yam Tuber (medium)', 'each', 'Produce', '20', '200', '1800', ['KIT' => '80']],
        ['KIT-TOMA-KG', 'Fresh Tomatoes', 'kg', 'Produce', '10', '150', '1500', ['KIT' => '60']],
        ['KIT-ONIO-KG', 'Onions', 'kg', 'Produce', '10', '120', '1100', ['KIT' => '50']],
        ['KIT-PEPP-KG', 'Fresh Pepper (Tatashe/Rodo)', 'kg', 'Produce', '5', '60', '2200', ['KIT' => '25']],
        ['KIT-CHKN-KG', 'Frozen Chicken', 'kg', 'Meat', '20', '300', '4200', ['KIT' => '120', 'RST' => '30']],
        ['KIT-BEEF-KG', 'Beef', 'kg', 'Meat', '15', '200', '5500', ['KIT' => '80']],
        ['KIT-FISH-KG', 'Catfish (fresh)', 'kg', 'Fish', '10', '100', '4800', ['KIT' => '40']],
        ['KIT-TILA-KG', 'Tilapia (frozen)', 'kg', 'Fish', '10', '100', '4000', ['KIT' => '40']],
        ['KIT-EGGS-30', 'Eggs (crate of 30)', 'crate', 'Dairy & Eggs', '10', '120', '3600', ['KIT' => '40', 'SUP' => '30']],
        ['KIT-OILV-25', 'Vegetable Oil 25L', 'jerrycan', 'Oils', '2', '24', '62000', ['KIT' => '8']],
        ['KIT-FLOU-50', 'Wheat Flour 50kg', 'bag', 'Baking', '2', '20', '48000', ['KIT' => '6']],
        ['KIT-SUGR-50', 'Sugar 50kg', 'bag', 'Baking', '2', '20', '78000', ['KIT' => '4', 'SUP' => '4']],
        ['KIT-SALT-1K', 'Table Salt 1kg', 'pack', 'Spices', '10', '100', '350', ['KIT' => '30', 'SUP' => '40']],
        ['KIT-SEAS-CB', 'Seasoning Cubes (box of 100)', 'box', 'Spices', '5', '60', '2800', ['KIT' => '20', 'SUP' => '20']],
        ['KIT-SPAG-500', 'Spaghetti 500g', 'pack', 'Pasta', '20', '300', '900', ['KIT' => '80', 'SUP' => '120']],
        ['KIT-PLTN-EA', 'Plantain (bunch)', 'bunch', 'Produce', '10', '100', '3500', ['KIT' => '40']],
        ['KIT-GARI-25', 'Garri 25kg', 'bag', 'Grains', '2', '20', '36000', ['KIT' => '6']],
        ['KIT-MILK-400', 'Peak Milk Powder 400g', 'tin', 'Dairy & Eggs', '12', '120', '3800', ['KIT' => '24', 'SUP' => '48']],
        ['KIT-BUTR-500', 'Butter 500g', 'pack', 'Dairy & Eggs', '6', '60', '3200', ['KIT' => '18']],
        // Supermarket
        ['SUP-INDM-70', 'Indomie Noodles 70g (carton of 40)', 'carton', 'Groceries', '6', '80', '7800', ['SUP' => '30']],
        ['SUP-BRED-EA', 'Sliced Bread Loaf', 'each', 'Bakery', '20', '150', '1300', ['SUP' => '60', 'RST' => '30']],
        ['SUP-BISC-EA', 'Cabin Biscuits Pack', 'pack', 'Snacks', '20', '200', '450', ['SUP' => '100']],
        ['SUP-CHIP-EA', 'Plantain Chips Pack', 'pack', 'Snacks', '20', '200', '300', ['SUP' => '100', 'BAR' => '48']],
        ['SUP-TISS-EA', 'Toilet Tissue (pack of 10)', 'pack', 'Household', '10', '100', '2600', ['SUP' => '40']],
        ['SUP-DETG-1K', 'Detergent Powder 1kg', 'pack', 'Household', '10', '80', '2200', ['SUP' => '30']],
        ['SUP-TOOT-EA', 'Toothpaste 100g', 'tube', 'Toiletries', '12', '120', '900', ['SUP' => '48']],
        ['SUP-SOAP-EA', 'Bathing Soap Bar', 'bar', 'Toiletries', '20', '240', '350', ['SUP' => '96']],
        ['SUP-SUNS-EA', 'Sunscreen Lotion 100ml', 'bottle', 'Toiletries', '6', '48', '4500', ['SUP' => '18', 'SPT' => '12', 'SAL' => '6']],
        ['SUP-BATT-AA', 'AA Batteries (pack of 4)', 'pack', 'Electronics', '10', '60', '1500', ['SUP' => '24']],
        // Salon
        ['SAL-SHMP-1L', 'Professional Shampoo 1L', 'bottle', 'Salon Consumables', '4', '36', '5500', ['SAL' => '12']],
        ['SAL-COND-1L', 'Conditioner 1L', 'bottle', 'Salon Consumables', '4', '36', '5800', ['SAL' => '12']],
        ['SAL-NAIL-EA', 'Nail Polish (assorted)', 'bottle', 'Salon Consumables', '10', '96', '1800', ['SAL' => '48']],
        ['SAL-HAIR-EA', 'Hair Relaxer Kit', 'kit', 'Salon Consumables', '6', '48', '4800', ['SAL' => '18']],
        ['SAL-TOWL-EA', 'Salon Towel', 'each', 'Linen', '10', '60', '2500', ['SAL' => '30']],
        // Sports store
        ['SPT-FBAL-EA', 'Football (size 5)', 'each', 'Sports Equipment', '4', '20', '9500', ['SPT' => '10']],
        ['SPT-TTBL-EA', 'Table Tennis Bat', 'each', 'Sports Equipment', '4', '20', '3500', ['SPT' => '12']],
        ['SPT-TBAL-EA', 'Tennis Ball (can of 3)', 'can', 'Sports Equipment', '6', '40', '4200', ['SPT' => '16']],
        ['SPT-TRKT-EA', 'Tennis Racket (rental)', 'each', 'Rental Equipment', '0', '0', '18000', []],
        ['SPT-BRKT-EA', 'Badminton Racket (rental)', 'each', 'Rental Equipment', '0', '0', '9000', []],
        ['SPT-JCKT-EA', 'Life Jacket (rental)', 'each', 'Rental Equipment', '4', '30', '7500', ['SPT' => '20']],
        ['SPT-PTWL-EA', 'Pool Towel (rental)', 'each', 'Rental Equipment', '10', '80', '2800', ['SPT' => '50']],
    ];

    public function run(): array
    {
        $site = Tenant::siteId() ?? $this->createTenant();
        $org = Tenant::organizationId();
        $orgB = Ids::toBinary($org);
        $siteB = Ids::toBinary($site);

        // --- facilities + locations ---
        $loc = [];
        $facilityIds = [];
        foreach (self::LOCATIONS as $key => [$name, $kind, $code, $facilityName]) {
            $facilityId = null;
            if ($code !== null) {
                $facilityId = $facilityIds[$code] ??= $this->facility($org, $site, $code, $facilityName);
            }
            $row = DB::table('stock_location')->where('site_id', $siteB)->where('name', $name)->first(['id']);
            if (! $row) {
                $id = Ids::uuid7();
                DB::table('stock_location')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => $orgB, 'site_id' => $siteB, 'facility_unit_id' => $facilityId ? Ids::toBinary($facilityId) : null,
                    'name' => $name, 'kind' => $kind, 'allow_negative' => 0, 'is_sale_default' => $kind === 'MAIN_STORE' || $kind === 'WASTE' ? 0 : 1,
                ]);
                $loc[$key] = $id;
            } else {
                $loc[$key] = Ids::fromBinary($row->id);
            }
        }

        // --- items ---
        $itemIds = [];
        foreach (self::ITEMS as [$sku, $name, $unit, $cat, $reorder]) {
            $row = DB::table('inventory_item')->where('organization_id', $orgB)->where('sku', $sku)->first(['id']);
            if (! $row) {
                $id = Ids::uuid7();
                DB::table('inventory_item')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => $orgB, 'sku' => $sku, 'name' => $name, 'unit' => $unit, 'category' => $cat, 'reorder_level' => $reorder,
                ]);
                $itemIds[$sku] = $id;
            } else {
                $itemIds[$sku] = Ids::fromBinary($row->id);
            }
        }

        // --- tagged rental assets ---
        foreach ([['SPT-TRKT-EA', 'SPT-RKT-', 6], ['SPT-BRKT-EA', 'SPT-BDM-', 6]] as [$sku, $prefix, $n]) {
            for ($i = 1; $i <= $n; $i++) {
                DB::table('rental_asset')->insertOrIgnore([
                    'id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $orgB, 'item_id' => Ids::toBinary($itemIds[$sku]),
                    'location_id' => Ids::toBinary($loc['SPT']), 'asset_tag' => $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                ]);
            }
        }

        // --- opening stock (only once) ---
        $seeded = false;
        if (! DB::table('stock_movement')->where('organization_id', $orgB)->exists()) {
            $docs = app(StockDocuments::class);
            $receiptLines = [];
            $transfers = [];
            foreach (self::ITEMS as [$sku, , , , , $mainQty, $cost, $out]) {
                if ($mainQty === '0') {
                    continue;
                }
                $receiptLines[] = ['itemId' => $itemIds[$sku], 'quantity' => $mainQty, 'unitCost' => $cost];
                foreach ($out as $key => $qty) {
                    $transfers[$key][] = ['itemId' => $itemIds[$sku], 'quantity' => $qty];
                }
            }
            DB::transaction(function () use ($docs, $loc, $receiptLines, $transfers) {
                $docs->receive(['locationId' => $loc['MAIN'], 'supplierName' => 'Demo Wholesale Supplies Ltd', 'supplierInvoice' => 'DEMO-OPENING-STOCK', 'note' => 'Demo opening stock', 'lines' => $receiptLines]);
                foreach ($transfers as $key => $lines) {
                    $docs->transfer(['fromLocationId' => $loc['MAIN'], 'toLocationId' => $loc[$key], 'note' => 'Demo opening distribution', 'lines' => $lines]);
                }
            });
            $seeded = true;
        }

        return ['items' => count($itemIds), 'locations' => count($loc), 'openingStockPosted' => $seeded];
    }

    private function facility(string $org, string $site, string $code, string $name): string
    {
        $row = DB::table('facility_unit')->where('site_id', Ids::toBinary($site))->where(fn ($q) => $q->where('code', $code)->orWhere('name', $name))->first(['id']);
        if ($row) {
            return Ids::fromBinary($row->id);
        }
        $id = Ids::uuid7();
        DB::table('facility_unit')->insert(['id' => Ids::toBinary($id), 'organization_id' => Ids::toBinary($org), 'site_id' => Ids::toBinary($site), 'code' => $code, 'name' => $name]);

        return $id;
    }

    /** Standalone runs on an empty database: create the demo organization + site. */
    private function createTenant(): string
    {
        $org = Ids::uuid7();
        $site = Ids::uuid7();
        DB::table('organization')->insert(['id' => Ids::toBinary($org), 'name' => '007 Resort & Spa']);
        DB::table('site')->insert(['id' => Ids::toBinary($site), 'organization_id' => Ids::toBinary($org), 'name' => '007 Resort & Spa']);

        return $site;
    }
}
