<?php

namespace App\Domain\Config\Services;

use App\Domain\Catalog\Services\CatalogAdmin;
use App\Domain\Config\Support\ConfigChange;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Ticket types: CRUD with an optional price (the linked TICKET product + default-list price that makes the ticket sellable). */
class TicketTypeAdminService
{
    public const FORMATS = ['INDIVIDUAL', 'COMBINED'];

    public const MODES = ['NONE', 'SINGLE_USE', 'MULTIPLE_ENTRY', 'TIME_LIMITED', 'ENTRY_EXIT', 'STAFF_APPROVAL'];

    public const VALIDITY = ['ISSUE_DAY', 'DURATION_MINUTES', 'BOOKING_SLOT'];

    public function __construct(private readonly FacilityLoader $facilities, private readonly CatalogAdmin $catalog) {}

    /** @return array<string, mixed> */
    public function present(object $r): array
    {
        $price = null;
        if ($r->product_id !== null) {
            $now = Fmt::now();
            $price = DB::table('price as p')->join('price_list as l', 'l.id', '=', 'p.price_list_id')->where('p.product_id', $r->product_id)->where('l.is_default', 1)->where('p.is_active', 1)
                ->where('p.valid_from', '<=', $now)->where(fn ($w) => $w->whereNull('p.valid_to')->orWhere('p.valid_to', '>', $now))
                ->where(fn ($w) => $w->whereNull('p.facility_unit_id')->orWhere('p.facility_unit_id', $r->facility_unit_id))->orderByRaw('p.facility_unit_id IS NULL ASC')->orderByDesc('p.valid_from')->value('p.amount');
        }

        return [
            'id' => Ids::fromBinary($r->id), 'code' => $r->code, 'name' => $r->name, 'facilityId' => Ids::fromBinary($r->facility_unit_id), 'format' => $r->format, 'validationMode' => $r->validation_mode,
            'validityKind' => $r->validity_kind, 'validityMinutes' => $r->validity_minutes === null ? null : (int) $r->validity_minutes, 'earlyEntryMinutes' => (int) $r->early_entry_minutes,
            'active' => (bool) $r->is_active, 'productId' => Fmt::u($r->product_id), 'price' => $price === null ? null : Money::normalize($price), 'rowVersion' => (int) $r->row_version,
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function create(array $in): array
    {
        try {
            return DB::transaction(function () use ($in): array {
                $f = $this->facilities->lock($in['facilityId']);
                $this->assertFacility($f);
                $code = strtoupper($in['code']);
                if (DB::table('ticket_type')->where('site_id', $f->site_id)->where('code', $code)->exists()) {
                    throw ApiProblem::conflict('ticket_type_code_taken', 'A ticket type with that code already exists.');
                }
                $this->assertValidity($in['validityKind'] ?? 'ISSUE_DAY', $in['validityMinutes'] ?? null);
                $id = Ids::uuid7();
                DB::table('ticket_type')->insert([
                    'id' => Ids::toBinary($id), 'organization_id' => $f->organization_id, 'site_id' => $f->site_id, 'facility_unit_id' => $f->id, 'code' => $code, 'name' => $in['name'],
                    'format' => $in['format'] ?? 'INDIVIDUAL', 'validation_mode' => $in['validationMode'] ?? 'SINGLE_USE', 'validity_kind' => $in['validityKind'] ?? 'ISSUE_DAY',
                    'validity_minutes' => $in['validityMinutes'] ?? null, 'early_entry_minutes' => $in['earlyEntryMinutes'] ?? 0, 'is_active' => ($in['active'] ?? true) ? 1 : 0, 'row_version' => 1,
                ]);
                if (array_key_exists('price', $in) && $in['price'] !== null) {
                    $productId = $this->ensureProduct($id, $code, $in['name'], Ids::fromBinary($f->id), $in['price']);
                    DB::table('ticket_type')->where('id', Ids::toBinary($id))->update(['product_id' => Ids::toBinary($productId)]);
                }
                $v = $this->present(DB::table('ticket_type')->where('id', Ids::toBinary($id))->first());
                ConfigChange::record('config.ticket_type.create', 'TicketType', $id, null, $v, 'ticketType', ['ticketType' => $v, 'organizationId' => Ids::fromBinary($f->organization_id), 'siteId' => Ids::fromBinary($f->site_id)], 1,
                    facilityId: $v['facilityId'], organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

                return $v;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ApiProblem::conflict('ticket_type_code_taken', 'A ticket type with that code already exists.');
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function update(string $id, array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($id, $in, $ifMatch): array {
            $r = Ids::isUuid($id) ? DB::table('ticket_type')->where('id', Ids::toBinary($id))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->lockForUpdate()->first() : null;
            $r ?? throw ApiProblem::notFound('not_found', 'Ticket type was not found.');
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'ticket type');
            $old = $this->present($r);
            $set = [];
            foreach (['name' => 'name', 'format' => 'format', 'validationMode' => 'validation_mode', 'validityKind' => 'validity_kind', 'validityMinutes' => 'validity_minutes', 'earlyEntryMinutes' => 'early_entry_minutes'] as $k => $col) {
                if (array_key_exists($k, $in) && $in[$k] !== $r->{$col} && (string) $in[$k] !== (string) $r->{$col}) {
                    $set[$col] = $in[$k];
                }
            }
            $kind = $set['validity_kind'] ?? $r->validity_kind;
            $minutes = array_key_exists('validity_minutes', $set) ? $set['validity_minutes'] : $r->validity_minutes;
            if (isset($set['validity_kind']) || array_key_exists('validity_minutes', $set)) {
                $this->assertValidity($kind, $minutes === null ? null : (int) $minutes);
            }
            if (array_key_exists('active', $in) && (bool) $in['active'] !== (bool) $r->is_active) {
                $set['is_active'] = $in['active'] ? 1 : 0;
            }
            if (isset($in['facilityId']) && Ids::normalize($in['facilityId']) !== Ids::fromBinary($r->facility_unit_id)) {
                if (DB::table('entitlement_item')->where('ticket_type_id', $r->id)->exists() || DB::table('bookable_resource')->where('ticket_type_id', $r->id)->exists()) {
                    throw ApiProblem::conflict('ticket_type_in_use', 'Tickets have been issued (or resources use this type), so its facility can no longer change. Create a new ticket type for the other facility.');
                }
                $f = $this->facilities->lock($in['facilityId']);
                $this->assertFacility($f);
                $set['facility_unit_id'] = $f->id;
            }
            $priceChanged = false;
            if (array_key_exists('price', $in) && $in['price'] !== null && Money::normalize($in['price']) !== ($old['price'] ?? null)) {
                $priceChanged = true;
            }
            if ($set === [] && ! $priceChanged) {
                return $old;
            }
            $facilityId = Ids::fromBinary($set['facility_unit_id'] ?? $r->facility_unit_id);
            if ($priceChanged) {
                $productId = $this->ensureProduct($id, $r->code, $set['name'] ?? $r->name, $facilityId, $in['price'], $r->product_id === null ? null : Ids::fromBinary($r->product_id));
                $set['product_id'] = Ids::toBinary($productId);
            }
            if (isset($set['name']) && $r->product_id !== null && ! $priceChanged) {
                $this->catalog->updateProduct(Ids::fromBinary($r->product_id), ['name' => $set['name']], null);
            }
            DB::table('ticket_type')->where('id', $r->id)->update($set + ['row_version' => $r->row_version + 1]);
            $new = $this->present(DB::table('ticket_type')->where('id', $r->id)->first());
            ConfigChange::record('config.ticket_type.update', 'TicketType', $id, $old, $new, 'ticketType', ['ticketType' => $new], $new['rowVersion'], facilityId: $new['facilityId'],
                organizationId: Ids::fromBinary($r->organization_id), siteId: Ids::fromBinary($r->site_id));

            return $new;
        });
    }

    private function assertFacility(object $f): void
    {
        if (! $f->is_active) {
            throw ApiProblem::unprocessable('validation_failed', 'The facility is inactive.', ['facilityId' => ['Choose an active facility.']]);
        }
        if (! DB::table('facility_capability')->where('facility_unit_id', $f->id)->whereIn('capability_code', ['TICKETING', 'TICKET_VALIDATION'])->where('is_enabled', 1)->exists()) {
            throw ApiProblem::unprocessable('capability_disabled', 'Turn on Ticket sales or Ticket validation for this facility first.', ['facilityId' => ['TICKETING or TICKET_VALIDATION must be enabled.']]);
        }
    }

    private function assertValidity(string $kind, ?int $minutes): void
    {
        if ($kind === 'DURATION_MINUTES' && ($minutes === null || $minutes < 1)) {
            throw ApiProblem::unprocessable('validation_failed', 'validityMinutes is required for DURATION_MINUTES.', ['validityMinutes' => ['Required (>= 1) when validityKind is DURATION_MINUTES.']]);
        }
        if ($kind !== 'DURATION_MINUTES' && $minutes !== null) {
            throw ApiProblem::unprocessable('validation_failed', 'validityMinutes only applies to DURATION_MINUTES.', ['validityMinutes' => ['Leave empty unless validityKind is DURATION_MINUTES.']]);
        }
    }

    /** The TICKET product (created on first price) that makes the type sellable; sets its list-wide price. */
    private function ensureProduct(string $typeId, string $code, string $name, string $facilityId, string $price, ?string $existingProductId = null): string
    {
        if ($existingProductId !== null) {
            $this->catalog->setPrice($existingProductId, $price, null);
            DB::table('product_facility')->insertOrIgnore(['product_id' => Ids::toBinary($existingProductId), 'facility_unit_id' => Ids::toBinary($facilityId)]);

            return $existingProductId;
        }
        $org = (string) Tenant::organizationId();
        $cat = DB::table('product_category')->where('organization_id', Ids::toBinary($org))->where('name', 'Tickets')->value('id');
        $catId = $cat !== null ? Ids::fromBinary($cat) : $this->catalog->createCategory(['name' => 'Tickets'])['id'];
        $p = $this->catalog->createProduct(['sku' => 'TKT-'.$code, 'name' => $name, 'categoryId' => $catId, 'kind' => 'TICKET', 'facilityIds' => [$facilityId], 'price' => $price]);

        return $p['id'];
    }
}
