<?php

namespace App\Domain\Catalog\Demo;

use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoIds;
use App\Support\Demo\DemoSeeder;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * DEV-ONLY demo catalog for the 007 Resort & Spa property (Nigerian menu, NGN prices): categories, prep routes, tax rates, the default
 * price list, ~70 products with per-facility availability and a few facility price overrides (the Indoor Club charges more).
 * Idempotent (deterministic ids + upserts). VAT stays OFF (ADR-0011) until an admin switches it on; basic groceries are VAT-exempt.
 */
class CatalogDemoSeeder implements DemoSeeder
{
    public function priority(): int
    {
        return 100;
    }

    /** name => sort order */
    private const CATEGORIES = [
        'Main Dishes' => 10, 'Soups & Swallow' => 20, 'Grills & Suya' => 30, 'Sides & Snacks' => 40, 'Desserts' => 50,
        'Beers & Stout' => 60, 'Wine & Spirits' => 70, 'Cocktails' => 80, 'Soft Drinks & Water' => 90, 'Hot Drinks' => 100,
        'Bakery' => 110, 'Groceries' => 120, 'Toiletries' => 130, 'Spa Treatments' => 140, 'Club Bottles' => 150, 'Tickets & Fees' => 160,
    ];

    /**
     * sku => [name, category, kind, route KITCHEN|BAR|NONE, price NGN, [facility codes], flags]  flags: exempt, stock
     * Food goes to the kitchen, drinks to the bar; retail/services need no preparation.
     *
     * @return array<string, array>
     */
    private function products(): array
    {
        $R = 'RESTAURANT';
        $C = 'INDOOR_CLUB';
        $B = 'BUSH_BAR';
        $P = 'POOL_BAR';
        $F = 'CAFE';
        $E = 'EVENT_CENTRE';

        return [
            // --- Restaurant kitchen ---
            'FD-JOL-CH' => ['Jollof Rice & Chicken', 'Main Dishes', 'GOOD', 'KITCHEN', '4500', [$R, $E]],
            'FD-FRI-TK' => ['Fried Rice & Turkey', 'Main Dishes', 'GOOD', 'KITCHEN', '5000', [$R, $E]],
            'FD-OFA-AY' => ['Ofada Rice & Ayamase', 'Main Dishes', 'GOOD', 'KITCHEN', '4800', [$R]],
            'FD-CHK-CH' => ['Chicken & Chips', 'Main Dishes', 'GOOD', 'KITCHEN', '4500', [$R, $P]],
            'FD-BRG-BF' => ['Beef Burger & Fries', 'Main Dishes', 'GOOD', 'KITCHEN', '4500', [$R, $P]],
            'FD-CLB-SW' => ['Club Sandwich', 'Main Dishes', 'GOOD', 'KITCHEN', '4000', [$R, $F]],
            'FD-EGU-PY' => ['Egusi Soup & Pounded Yam', 'Soups & Swallow', 'GOOD', 'KITCHEN', '5500', [$R]],
            'FD-AFA-EB' => ['Afang Soup & Eba', 'Soups & Swallow', 'GOOD', 'KITCHEN', '5500', [$R]],
            'FD-BAN-ST' => ['Banga Soup & Starch', 'Soups & Swallow', 'GOOD', 'KITCHEN', '5800', [$R]],
            'FD-AMA-EW' => ['Amala, Gbegiri & Ewedu', 'Soups & Swallow', 'GOOD', 'KITCHEN', '4000', [$R]],
            'FD-PEP-GT' => ['Goat Meat Pepper Soup', 'Soups & Swallow', 'GOOD', 'KITCHEN', '3500', [$R, $B, $C]],
            'FD-PEP-CF' => ['Catfish Pepper Soup', 'Soups & Swallow', 'GOOD', 'KITCHEN', '4500', [$R, $B]],
            'FD-SUY-BF' => ['Beef Suya', 'Grills & Suya', 'GOOD', 'KITCHEN', '3000', [$R, $B, $P]],
            'FD-SUY-CK' => ['Chicken Suya', 'Grills & Suya', 'GOOD', 'KITCHEN', '3500', [$R, $B]],
            'FD-GRL-TL' => ['Grilled Tilapia', 'Grills & Suya', 'GOOD', 'KITCHEN', '6500', [$R, $B]],
            'FD-GRL-CF' => ['Grilled Catfish', 'Grills & Suya', 'GOOD', 'KITCHEN', '7500', [$R, $B]],
            'FD-DODO' => ['Fried Plantain (Dodo)', 'Sides & Snacks', 'GOOD', 'KITCHEN', '1200', [$R, $B]],
            'FD-MOIMOI' => ['Moi Moi', 'Sides & Snacks', 'GOOD', 'KITCHEN', '1000', [$R]],
            'FD-PUFF' => ['Puff Puff (6 pcs)', 'Sides & Snacks', 'GOOD', 'KITCHEN', '1000', [$R, $F]],
            'FD-CHINCHIN' => ['Chin Chin (bag)', 'Sides & Snacks', 'GOOD', 'NONE', '800', [$R, $P, $C, $F]],
            'FD-FRU-SL' => ['Fruit Salad', 'Desserts', 'GOOD', 'KITCHEN', '2000', [$R, $F]],
            'FD-ICE-CR' => ['Ice Cream (2 scoops)', 'Desserts', 'GOOD', 'KITCHEN', '1500', [$R, $F, $P]],
            // --- Beers ---
            'BR-STAR' => ['Star Lager 60cl', 'Beers & Stout', 'GOOD', 'BAR', '1500', [$R, $C, $B, $P, $E], 'stock', [$C => '2000']],
            'BR-GULD' => ['Gulder 60cl', 'Beers & Stout', 'GOOD', 'BAR', '1500', [$R, $C, $B, $P, $E], 'stock', [$C => '2000']],
            'BR-HEIN' => ['Heineken 60cl', 'Beers & Stout', 'GOOD', 'BAR', '1800', [$R, $C, $B, $P, $E], 'stock', [$C => '2500']],
            'BR-GUIN' => ['Guinness Stout 60cl', 'Beers & Stout', 'GOOD', 'BAR', '1800', [$R, $C, $B, $P], 'stock', [$C => '2500']],
            'BR-LEGN' => ['Legend Extra Stout', 'Beers & Stout', 'GOOD', 'BAR', '1500', [$R, $C, $B, $P], 'stock'],
            'BR-TROP' => ['Trophy Lager', 'Beers & Stout', 'GOOD', 'BAR', '1300', [$R, $B, $P], 'stock'],
            'BR-PALM' => ['Palm Wine (calabash)', 'Beers & Stout', 'GOOD', 'BAR', '1000', [$B]],
            // --- Wine & spirits ---
            'WN-RED-GL' => ['Red Wine (glass)', 'Wine & Spirits', 'GOOD', 'BAR', '3000', [$R, $C, $E]],
            'WN-WHT-GL' => ['White Wine (glass)', 'Wine & Spirits', 'GOOD', 'BAR', '3000', [$R, $C, $E]],
            'SP-HEN-SH' => ['Hennessy VS (shot)', 'Wine & Spirits', 'GOOD', 'BAR', '3500', [$C, $B], 'stock'],
            'SP-JD-SH' => ["Jack Daniel's (shot)", 'Wine & Spirits', 'GOOD', 'BAR', '3000', [$C, $B, $P], 'stock'],
            'SP-SMI-SH' => ['Smirnoff Vodka (shot)', 'Wine & Spirits', 'GOOD', 'BAR', '2000', [$C, $B, $P], 'stock'],
            // --- Cocktails ---
            'CK-CHAP' => ['Chapman', 'Cocktails', 'GOOD', 'BAR', '2500', [$R, $C, $B, $P, $E]],
            'CK-MOJI' => ['Mojito', 'Cocktails', 'GOOD', 'BAR', '4000', [$C, $P]],
            'CK-LIIT' => ['Long Island Iced Tea', 'Cocktails', 'GOOD', 'BAR', '4500', [$C, $P]],
            'CK-PINA' => ['Pina Colada', 'Cocktails', 'GOOD', 'BAR', '4000', [$P, $C]],
            'CK-ZOBO' => ['Zobo (chilled)', 'Cocktails', 'GOOD', 'BAR', '1500', [$R, $B, $P, $F]],
            // --- Soft drinks & water ---
            'SD-COKE' => ['Coca-Cola 50cl', 'Soft Drinks & Water', 'GOOD', 'BAR', '700', [$R, $C, $B, $P, $F, $E], 'stock'],
            'SD-FANT' => ['Fanta 50cl', 'Soft Drinks & Water', 'GOOD', 'BAR', '700', [$R, $C, $B, $P, $F, $E], 'stock'],
            'SD-SPRT' => ['Sprite 50cl', 'Soft Drinks & Water', 'GOOD', 'BAR', '700', [$R, $C, $B, $P, $F, $E], 'stock'],
            'SD-MALT' => ['Maltina', 'Soft Drinks & Water', 'GOOD', 'BAR', '1000', [$R, $C, $B, $P, $E], 'stock'],
            'SD-WATR' => ['Bottled Water 75cl', 'Soft Drinks & Water', 'GOOD', 'BAR', '500', [$R, $C, $B, $P, $F, $E, 'RECEPTION'], 'stock'],
            'SD-OJ' => ['Orange Juice (fresh)', 'Soft Drinks & Water', 'GOOD', 'BAR', '1500', [$R, $F, $P]],
            // --- Cafe ---
            'CF-ESPR' => ['Espresso', 'Hot Drinks', 'GOOD', 'BAR', '1500', [$F]],
            'CF-CAPP' => ['Cappuccino', 'Hot Drinks', 'GOOD', 'BAR', '1800', [$F, $R]],
            'CF-LATT' => ['Latte', 'Hot Drinks', 'GOOD', 'BAR', '2000', [$F, $R]],
            'CF-TEA' => ['Tea (Lipton)', 'Hot Drinks', 'GOOD', 'BAR', '800', [$F, $R]],
            'CF-CHOC' => ['Hot Chocolate', 'Hot Drinks', 'GOOD', 'BAR', '1800', [$F]],
            'BK-MPIE' => ['Meat Pie', 'Bakery', 'GOOD', 'NONE', '1200', [$F, 'SUPERMARKET'], 'stock'],
            'BK-CPIE' => ['Chicken Pie', 'Bakery', 'GOOD', 'NONE', '1500', [$F], 'stock'],
            'BK-CROI' => ['Butter Croissant', 'Bakery', 'GOOD', 'NONE', '1500', [$F], 'stock'],
            'BK-DONU' => ['Doughnut', 'Bakery', 'GOOD', 'NONE', '800', [$F], 'stock'],
            'BK-BRED' => ['Sliced Bread (loaf)', 'Bakery', 'GOOD', 'NONE', '1400', ['SUPERMARKET', 'SUPER_STORE'], 'stock,exempt'],
            // --- Supermarket ---
            'GR-RICE5' => ['Rice 5kg', 'Groceries', 'GOOD', 'NONE', '8500', ['SUPERMARKET', 'SUPER_STORE'], 'stock,exempt'],
            'GR-OIL1' => ['Vegetable Oil 1L', 'Groceries', 'GOOD', 'NONE', '2800', ['SUPERMARKET', 'SUPER_STORE'], 'stock,exempt'],
            'GR-SUGR' => ['Sugar 1kg', 'Groceries', 'GOOD', 'NONE', '1500', ['SUPERMARKET', 'SUPER_STORE'], 'stock,exempt'],
            'GR-EGGS' => ['Eggs (crate of 30)', 'Groceries', 'GOOD', 'NONE', '4500', ['SUPERMARKET', 'SUPER_STORE'], 'stock,exempt'],
            'GR-MILK' => ['Peak Milk Tin', 'Groceries', 'GOOD', 'NONE', '1200', ['SUPERMARKET', 'SUPER_STORE'], 'stock'],
            'GR-NOOD' => ['Indomie Noodles (carton)', 'Groceries', 'GOOD', 'NONE', '9500', ['SUPERMARKET', 'SUPER_STORE'], 'stock,exempt'],
            'GR-BISC' => ['Digestive Biscuits', 'Groceries', 'GOOD', 'NONE', '900', ['SUPERMARKET', 'SUPER_STORE'], 'stock'],
            'TL-TOOTH' => ['Toothpaste', 'Toiletries', 'GOOD', 'NONE', '900', ['SUPERMARKET', 'SUPER_STORE'], 'stock'],
            'TL-SOAP' => ['Bathing Soap', 'Toiletries', 'GOOD', 'NONE', '500', ['SUPERMARKET', 'SUPER_STORE'], 'stock'],
            'TL-TISS' => ['Toilet Tissue (4 rolls)', 'Toiletries', 'GOOD', 'NONE', '1600', ['SUPERMARKET', 'SUPER_STORE'], 'stock'],
            // --- Spa (services) ---
            'SPA-MSG60' => ['Full Body Massage (60 min)', 'Spa Treatments', 'SERVICE', 'NONE', '25000', ['BEAUTY_SPA']],
            'SPA-HOTST' => ['Hot Stone Massage (75 min)', 'Spa Treatments', 'SERVICE', 'NONE', '32000', ['BEAUTY_SPA']],
            'SPA-FACIA' => ['Deep Cleansing Facial', 'Spa Treatments', 'SERVICE', 'NONE', '15000', ['BEAUTY_SPA']],
            'SPA-MANI' => ['Manicure', 'Spa Treatments', 'SERVICE', 'NONE', '8000', ['BEAUTY_SPA']],
            'SPA-PEDI' => ['Pedicure', 'Spa Treatments', 'SERVICE', 'NONE', '9000', ['BEAUTY_SPA']],
            'SPA-SAUNA' => ['Sauna Session', 'Spa Treatments', 'SERVICE', 'NONE', '10000', ['BEAUTY_SPA']],
            // --- Club bottles ---
            'BT-HEN-VS' => ['Hennessy VS (bottle)', 'Club Bottles', 'GOOD', 'BAR', '45000', [$C], 'stock'],
            'BT-MOET' => ['Moet & Chandon (bottle)', 'Club Bottles', 'GOOD', 'BAR', '65000', [$C], 'stock'],
            'BT-SMIR' => ['Smirnoff Vodka (bottle)', 'Club Bottles', 'GOOD', 'BAR', '18000', [$C, $E], 'stock'],
            // --- Tickets & fees ---
            'TK-POOL' => ['Pool Day Pass', 'Tickets & Fees', 'TICKET', 'NONE', '5000', ['RECEPTION']],
            'TK-ARENA' => ['Sports Arena Entry', 'Tickets & Fees', 'TICKET', 'NONE', '2000', ['RECEPTION']],
            'FEE-CORK' => ['Corkage Fee', 'Tickets & Fees', 'FEE', 'NONE', '5000', [$R, $C, $E]],
        ];
    }

    public function run(DemoContext $ctx): void
    {
        $org = DemoIds::org();
        if (! DB::table('organization')->where('id', Ids::toBinary($org))->exists()) {
            $ctx->info('  (organization not seeded; skipping catalog)');

            return;
        }
        $orgBin = Ids::toBinary($org);
        $now = now('UTC')->format('Y-m-d H:i:s.u');

        foreach (['KITCHEN' => ['Kitchen', 'KITCHEN'], 'BAR' => ['Bar', 'BAR'], 'NONE' => ['No preparation', 'NONE']] as $code => [$name, $kind]) {
            DB::table('prep_route')->updateOrInsert(['id' => Ids::toBinary(DemoIds::of("prep_route:$code"))], ['organization_id' => $orgBin, 'code' => $code, 'name' => $name, 'kind' => $kind]);
        }
        foreach (['VAT_STD' => ['Standard VAT', '7.5'], 'VAT_EXEMPT' => ['VAT exempt', '0']] as $code => [$name, $rate]) {
            DB::table('tax_rate')->updateOrInsert(['id' => Ids::toBinary(DemoIds::of("tax_rate:$code"))], ['organization_id' => $orgBin, 'code' => $code, 'name' => $name, 'rate_percent' => $rate, 'is_active' => 1]);
        }
        $list = Ids::toBinary(DemoIds::of('price_list:standard'));
        DB::table('price_list')->updateOrInsert(['id' => $list], ['organization_id' => $orgBin, 'name' => 'Standard', 'currency' => 'NGN', 'is_default' => 1, 'is_active' => 1]);
        foreach (self::CATEGORIES as $name => $sort) {
            DB::table('product_category')->updateOrInsert(['id' => Ids::toBinary(DemoIds::of("category:$name"))], ['organization_id' => $orgBin, 'name' => $name, 'sort_order' => $sort, 'is_active' => 1]);
        }

        $n = 0;
        $facilityMissing = [];
        foreach ($this->products() as $sku => $p) {
            [$name, $cat, $kind, $route, $price, $facilities] = $p;
            $flags = explode(',', $p[6] ?? '');
            $overrides = $p[7] ?? [];
            $pid = Ids::toBinary(DemoIds::of("product:$sku"));
            DB::table('product')->updateOrInsert(['id' => $pid], [
                'organization_id' => $orgBin, 'category_id' => Ids::toBinary(DemoIds::of("category:$cat")), 'sku' => $sku, 'name' => $name, 'kind' => $kind,
                'tax_rate_id' => Ids::toBinary(DemoIds::of(in_array('exempt', $flags, true) ? 'tax_rate:VAT_EXEMPT' : 'tax_rate:VAT_STD')),
                'tax_exempt' => 0, 'prep_route_id' => Ids::toBinary(DemoIds::of("prep_route:$route")), 'track_stock' => in_array('stock', $flags, true) ? 1 : 0,
                'is_active' => 1, 'deleted_at' => null,
            ]);
            DB::table('price')->updateOrInsert(['id' => Ids::toBinary(DemoIds::of("price:$sku:*"))], [
                'price_list_id' => $list, 'product_id' => $pid, 'facility_unit_id' => null, 'amount' => $price, 'valid_from' => '2026-01-01 00:00:00.000000', 'valid_to' => null, 'is_active' => 1,
            ]);
            foreach ($facilities as $i => $code) {
                $fid = DemoIds::facility($code);
                if (! DB::table('facility_unit')->where('id', Ids::toBinary($fid))->exists()) {
                    $facilityMissing[$code] = true;

                    continue;
                }
                DB::table('product_facility')->updateOrInsert(['product_id' => $pid, 'facility_unit_id' => Ids::toBinary($fid)], ['is_available' => 1, 'unavailable_reason' => null, 'sort_order' => $i]);
            }
            foreach ($overrides as $code => $amount) {
                DB::table('price')->updateOrInsert(['id' => Ids::toBinary(DemoIds::of("price:$sku:$code"))], [
                    'price_list_id' => $list, 'product_id' => $pid, 'facility_unit_id' => Ids::toBinary(DemoIds::facility($code)), 'amount' => $amount,
                    'valid_from' => '2026-01-01 00:00:00.000000', 'valid_to' => null, 'is_active' => 1,
                ]);
            }
            $n++;
        }
        DB::table('product')->where('organization_id', $orgBin)->update(['updated_at' => $now]);
        $ctx->info('  catalog: '.count(self::CATEGORIES)." categories, {$n} products with NGN prices (VAT off by default; groceries VAT-exempt)".($facilityMissing ? ' [skipped unknown facilities: '.implode(',', array_keys($facilityMissing)).']' : ''));
    }
}
