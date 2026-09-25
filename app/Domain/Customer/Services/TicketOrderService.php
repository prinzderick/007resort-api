<?php

namespace App\Domain\Customer\Services;

use App\Domain\Catalog\Services\Pricing;
use App\Domain\Customer\Support\TicketCatalog;
use App\Domain\Guest\Support\GuestContact;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Sync\Services\SiteAvailability;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Online pool-ticket orders. The customer sends product ids + counts; the SERVER prices them (Catalog Pricing), creates an
 * unpaid ONLINE order owned by the customer (`customer_order`), and payment goes through /payments/paystack/initialize {orderIds}.
 * Entitlements (one QR per person, valid on the visit day) are issued by Ticketing when Payments fires PaymentCaptured.
 * Same-day visits are refused when the property's Local node is not fresh (SiteAvailability gate, ADR-0013 s4): the gate runs
 * BEFORE any order/payment exists.
 */
class TicketOrderService
{
    public function __construct(private readonly OrderService $orders, private readonly Pricing $pricing, private readonly SiteAvailability $site, private readonly PublicCatalog $catalog) {}

    /** @param array{facilityId: string, visitDate: string, lines: list<array{productId: string, quantity: int}>} $in @return array<string, mixed> */
    public function create(array $in, ?string $customerId, ?GuestContact $guest = null): array
    {
        $tz = config('booking.timezone', 'Africa/Lagos');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $visit = CarbonImmutable::parse($in['visitDate'], $tz)->startOfDay();
        if ($visit < $today || $visit > $today->addDays((int) config('customer.tickets.max_days_ahead'))) {
            throw ApiProblem::unprocessable('validation_failed', 'visitDate is outside the bookable window.', ['visitDate' => ['outside window']]);
        }
        $qty = array_sum(array_map(fn ($l) => (int) $l['quantity'], $in['lines']));
        if ($qty < 1 || $qty > (int) config('customer.tickets.max_per_order')) {
            throw ApiProblem::unprocessable('validation_failed', 'Between 1 and '.config('customer.tickets.max_per_order').' tickets per order.', ['lines' => ['quantity out of range']]);
        }
        $facilityBin = Ids::toBinary(strtolower($in['facilityId']));
        $fac = DB::table('facility_unit')->where('id', $facilityBin)->where('is_active', 1)->whereNull('deleted_at')->first();
        if ($fac === null) {
            throw ApiProblem::notFound('not_found', 'Facility not found.');
        }
        if ($visit->equalTo($today) && ! $this->site->isLocalFresh(Ids::fromBinary($fac->site_id))) {
            throw ApiProblem::conflict('site_offline', 'Same-day tickets are temporarily unavailable online. Please choose a later date or buy at the gate.');
        }
        $customer = $guest !== null ? (object) ['full_name' => $guest->name] : DB::table('customer')->where('id', Ids::toBinary((string) $customerId))->first();
        $accessFacility = $fac;
        $allowed = $this->catalog->ticketProductIds(Ids::fromBinary($fac->organization_id), Ids::fromBinary($fac->id));
        $sell = [];
        foreach ($in['lines'] as $l) {
            $pid = strtolower($l['productId']);
            $s = in_array($pid, $allowed, true) ? $this->catalog->sellFacility($pid, Ids::fromBinary($fac->id)) : null;
            if ($s === null) {
                throw ApiProblem::unprocessable('validation_failed', 'That ticket is not available here.', ['lines' => ['unknown or unavailable ticket product']]);
            }
            $sell[$s] = true;
        }
        if (count($sell) !== 1) {
            throw ApiProblem::unprocessable('validation_failed', 'Tickets from different sales points cannot share one order.', ['lines' => ['mixed sales points']]);
        }
        $fac = DB::table('facility_unit')->where('id', Ids::toBinary(array_key_first($sell)))->first(); // the SELLING facility owns the order and payment

        return DB::transaction(function () use ($in, $fac, $accessFacility, $visit, $customer, $customerId): array {
            $staff = $this->systemStaff($fac);
            $id = Ids::uuid7();
            $idBin = Ids::toBinary($id);
            DB::table('order')->insert([
                'id' => $idBin, 'organization_id' => $fac->organization_id, 'site_id' => $fac->site_id, 'facility_unit_id' => $fac->id,
                'order_number' => $this->nextNumber($fac), 'channel' => 'ONLINE', 'customer_name' => $customer->full_name, 'status' => 'DRAFT', 'created_by' => $staff,
            ]);
            $setting = $this->pricing->setting(Ids::fromBinary($fac->organization_id));
            $line = 0;
            $adults = $children = 0;
            foreach ($in['lines'] as $l) {
                $product = DB::table('product')->where('id', Ids::toBinary(strtolower($l['productId'])))->where('organization_id', $fac->organization_id)->whereNull('deleted_at')->first();
                $pf = $product ? DB::table('product_facility')->where('product_id', $product->id)->where('facility_unit_id', $fac->id)->first() : null;
                if (! $product || $product->kind !== 'TICKET' || ! $pf || ! $product->is_active || ! $pf->is_available) {
                    throw ApiProblem::unprocessable('validation_failed', 'That ticket is not available here.', ['lines' => ['unknown or unavailable ticket product']]);
                }
                $price = $this->pricing->unitPrice(Ids::fromBinary($product->id), Ids::fromBinary($fac->id)) ?? throw ApiProblem::unprocessable('price_missing', "'{$product->name}' has no price.");
                $rate = $this->pricing->rateFor($product, $setting);
                $q = (int) $l['quantity'];
                $a = Pricing::line($price, $q, $rate, $setting['pricesTaxInclusive']);
                DB::table('order_line')->insert([
                    'id' => Ids::toBinary(Ids::uuid7()), 'order_id' => $idBin, 'product_id' => $product->id, 'line_no' => ++$line, 'product_name' => $product->name, 'sku' => $product->sku,
                    'quantity' => $q, 'unit_price' => $price, 'tax_rate_percent' => $rate, 'tax_inclusive' => $setting['pricesTaxInclusive'] ? 1 : 0,
                    'gross_amount' => $a['gross'], 'discount_amount' => $a['discount'], 'tax_amount' => $a['tax'], 'line_total' => $a['total'],
                    'status' => 'PENDING', 'prep_route_kind' => 'NONE',
                ]);
                TicketCatalog::category($product->name, $product->sku) === 'CHILD' ? $children += $q : $adults += $q;
            }
            $this->orders->recalc($idBin);
            DB::table('customer_order')->insert([
                'order_id' => $idBin, 'customer_id' => $customerId === null ? null : Ids::toBinary($customerId), 'facility_unit_id' => $accessFacility->id, 'visit_date' => $visit->format('Y-m-d'),
                'adult_count' => $adults, 'child_count' => $children,
            ]);
            $fresh = DB::table('order')->where('id', $idBin)->first();
            Audit::record('customer.ticket_order.create', 'Order', $id, null, ['customerId' => $customerId, 'guest' => $customerId === null, 'visitDate' => $visit->format('Y-m-d'), 'total' => Money::of($fresh->total)->amount, 'adults' => $adults, 'children' => $children],
                organizationId: Ids::fromBinary($fac->organization_id), siteId: Ids::fromBinary($fac->site_id), facilityUnitId: Ids::fromBinary($fac->id));
            Outbox::record('OrderCreated', 'Order', $id, $this->orders->outboxPayload($fresh) + ['customerId' => $customerId, 'guest' => $customerId === null, 'visitDate' => $visit->format('Y-m-d')],
                organizationId: Ids::fromBinary($fac->organization_id), siteId: Ids::fromBinary($fac->site_id), facilityId: Ids::fromBinary($fac->id));

            return $this->present($id);
        });
    }

    /** @return array<string, mixed> */
    public function present(string $orderId): array
    {
        $bin = Ids::toBinary($orderId);
        $o = DB::table('order')->where('id', $bin)->first();
        $co = DB::table('customer_order')->where('order_id', $bin)->first();
        $lines = DB::table('order_line')->where('order_id', $bin)->whereNotIn('status', ['REMOVED', 'VOIDED'])->orderBy('line_no')->get();
        $ents = DB::table('entitlement')->where('order_id', $bin)->orderBy('id')->pluck('id')->map(fn ($b) => Ids::fromBinary($b))->all();
        $due = Money::of($o->total)->sub(Money::of($o->amount_paid));

        return [
            'id' => $orderId, 'number' => $o->order_number, 'facilityId' => Ids::fromBinary($o->facility_unit_id), 'status' => $o->status, 'channel' => $o->channel,
            'visitDate' => $co?->visit_date, 'currency' => $o->currency, 'subtotal' => Money::of($o->subtotal)->amount, 'total' => Money::of($o->total)->amount,
            'amountPaid' => Money::of($o->amount_paid)->amount, 'amountDue' => $due->isNegative() ? '0.0000' : $due->amount, 'paid' => $due->isZero() || $due->isNegative(),
            'lines' => $lines->map(fn ($l) => ['id' => Ids::fromBinary($l->id), 'productId' => Ids::fromBinary($l->product_id), 'name' => $l->product_name, 'quantity' => (int) $l->quantity,
                'unitPrice' => Money::of($l->unit_price)->amount, 'lineTotal' => Money::of($l->line_total)->amount])->all(),
            'adultCount' => (int) ($co->adult_count ?? 0), 'childCount' => (int) ($co->child_count ?? 0), 'entitlementIds' => $ents,
            'createdAt' => CarbonImmutable::parse($o->created_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** Orders need a `created_by` staff row: one inactive, credential-less "Online ordering" staff per site (cannot log in). */
    private function systemStaff(object $fac): string
    {
        $q = DB::table('staff')->where('site_id', $fac->site_id)->where('staff_number', 'ONLINE');
        if ($id = $q->value('id')) {
            return $id;
        }
        $id = Ids::toBinary(Ids::uuid7());
        DB::table('staff')->insertOrIgnore(['id' => $id, 'organization_id' => $fac->organization_id, 'site_id' => $fac->site_id, 'staff_number' => 'ONLINE', 'first_name' => 'Online', 'last_name' => 'Ordering', 'is_active' => 0]);

        return DB::table('staff')->where('site_id', $fac->site_id)->where('staff_number', 'ONLINE')->value('id');
    }

    private function nextNumber(object $facility): string
    {
        DB::statement('INSERT INTO order_number_counter (facility_unit_id, seq_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE seq_value = LAST_INSERT_ID(seq_value + 1)', [$facility->id]);
        $n = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('%s-%06d', strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $facility->code) ?: 'ORD', 0, 6)), $n);
    }
}
