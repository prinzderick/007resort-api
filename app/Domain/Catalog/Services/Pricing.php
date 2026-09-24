<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Organization\Services\TaxSettingService;
use App\Support\Ids;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Server-side price / tax / line maths. Decimal strings + bcmath only (Money), never floats.
 *
 * Tax model (ADR-0011):
 *  - VAT disabled  -> every rate is 0, tax 0, total = net.
 *  - VAT enabled   -> rate = product tax_rate (if set) else the org default; tax_exempt products get 0.
 *  - prices_tax_inclusive = true : tax is carved out of the (net-of-discount) amount, total = net.
 *  - prices_tax_inclusive = false: tax is added on top,                          total = net + tax.
 */
final class Pricing
{
    public function __construct(private readonly TaxSettingService $tax) {}

    /** Effective unit price for a product at a facility: facility price beats list-wide; latest valid_from wins. */
    public function unitPrice(string $productId, string $facilityId, ?string $at = null): ?string
    {
        $at ??= now('UTC')->format('Y-m-d H:i:s.u');
        $row = DB::table('price as p')
            ->join('price_list as pl', 'pl.id', '=', 'p.price_list_id')
            ->where('p.product_id', Ids::toBinary($productId))
            ->where('p.is_active', 1)->where('pl.is_active', 1)->where('pl.is_default', 1)
            ->where('p.valid_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('p.valid_to')->orWhere('p.valid_to', '>', $at))
            ->where(fn ($q) => $q->whereNull('p.facility_unit_id')->orWhere('p.facility_unit_id', Ids::toBinary($facilityId)))
            ->orderByRaw('p.facility_unit_id IS NULL ASC')->orderByDesc('p.valid_from')
            ->first(['p.amount']);

        return $row ? Money::normalize($row->amount) : null;
    }

    /**
     * Batch price lookup for a facility: productId => amount.
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    public function unitPrices(array $productIds, string $facilityId): array
    {
        if ($productIds === []) {
            return [];
        }
        $at = now('UTC')->format('Y-m-d H:i:s.u');
        $rows = DB::table('price as p')
            ->join('price_list as pl', 'pl.id', '=', 'p.price_list_id')
            ->whereIn('p.product_id', array_map(Ids::toBinary(...), $productIds))
            ->where('p.is_active', 1)->where('pl.is_active', 1)->where('pl.is_default', 1)
            ->where('p.valid_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('p.valid_to')->orWhere('p.valid_to', '>', $at))
            ->where(fn ($q) => $q->whereNull('p.facility_unit_id')->orWhere('p.facility_unit_id', Ids::toBinary($facilityId)))
            ->orderByRaw('p.facility_unit_id IS NULL DESC')->orderBy('p.valid_from') // least specific first; later rows overwrite
            ->get(['p.product_id', 'p.amount']);
        $out = [];
        foreach ($rows as $r) {
            $out[Ids::fromBinary($r->product_id)] = Money::normalize($r->amount);
        }

        return $out;
    }

    /**
     * Tax rate percent applying to a product ("0" when VAT is off or the product is exempt).
     *
     * @param  object{tax_exempt: int|bool, tax_rate_id: ?string}  $product  product row
     * @param  array{vatEnabled: bool, vatRatePercent: string}  $setting
     */
    public function rateFor(object $product, array $setting): string
    {
        if (! $setting['vatEnabled'] || $product->tax_exempt) {
            return '0';
        }
        if ($product->tax_rate_id !== null) {
            $r = DB::table('tax_rate')->where('id', $product->tax_rate_id)->where('is_active', 1)->value('rate_percent');
            if ($r !== null) {
                return rtrim(rtrim($r, '0'), '.') ?: '0';
            }
        }

        return $setting['vatRatePercent'];
    }

    /** @return array{vatEnabled: bool, vatRatePercent: string, pricesTaxInclusive: bool, vatNumber: ?string, rowVersion: int} */
    public function setting(string $organizationId): array
    {
        return $this->tax->get($organizationId);
    }

    /**
     * One order line's amounts. `unitPrice` is the effective (possibly overridden) unit price.
     *
     * @return array{gross: string, discount: string, tax: string, total: string}
     */
    public static function line(string $unitPrice, int $quantity, string $ratePercent, bool $inclusive, string $discount = '0'): array
    {
        $gross = Money::of($unitPrice)->mul($quantity);
        $disc = Money::of($discount);
        if ($disc->compare($gross) > 0) {
            $disc = $gross;
        }
        $net = $gross->sub($disc);
        if (bccomp($ratePercent, '0', 4) === 0) {
            return ['gross' => $gross->amount, 'discount' => $disc->amount, 'tax' => '0.0000', 'total' => $net->amount];
        }
        if ($inclusive) {
            // tax = net * r / (100 + r)
            $tax = Money::roundHalfUp(bcdiv(bcmul($net->amount, $ratePercent, 12), bcadd('100', $ratePercent, 8), 12));

            return ['gross' => $gross->amount, 'discount' => $disc->amount, 'tax' => $tax, 'total' => $net->amount];
        }
        $tax = $net->percent($ratePercent);

        return ['gross' => $gross->amount, 'discount' => $disc->amount, 'tax' => $tax->amount, 'total' => $net->add($tax)->amount];
    }
}
