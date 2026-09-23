<?php

namespace App\Domain\Customer\Services;

use App\Domain\Sync\Services\SiteAvailability;
use App\Support\Ids;
use App\Support\Node;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** GET /public/site: only what a stranger may see. Online availability is computed from data (resources, ticket products), never from names. */
class PublicSiteService
{
    public function __construct(private readonly SiteAvailability $availability, private readonly PublicCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $siteId = Tenant::siteId();
        $site = $siteId ? DB::table('site')->where('id', Ids::toBinary($siteId))->first() : null;
        $org = Tenant::organizationId() ? DB::table('organization')->where('id', Ids::toBinary(Tenant::organizationId()))->first() : null;
        $fresh = $this->availability->isLocalFresh($siteId);
        $cfg = (array) config('customer.site');

        $facilities = [];
        foreach (DB::table('facility_unit')->where('site_id', Ids::toBinary((string) $siteId))->where('is_active', 1)->whereNull('deleted_at')->orderBy('code')->get() as $f) {
            $res = DB::table('bookable_resource')->where('facility_unit_id', $f->id)->where('is_active', 1)->whereNull('deleted_at');
            $bookable = (clone $res)->where('online_bookable', 1)->exists();
            $hours = (clone $res)->join('availability_schedule as s', 's.resource_id', '=', 'bookable_resource.id')->selectRaw('MIN(s.open_time) o, MAX(s.close_time) c')->first();
            $tickets = $this->catalog->ticketProductIds(Ids::fromBinary($f->organization_id), Ids::fromBinary($f->id)) !== [];
            $notice = null;
            if ($tickets && ! $fresh) {
                $notice = 'Same-day online tickets are temporarily unavailable. Advance tickets are still on sale.';
            }
            $facilities[] = [
                'id' => Ids::fromBinary($f->id), 'code' => $f->code, 'kind' => $f->kind ?? 'GENERAL', 'name' => $f->name, 'description' => null,
                'openingHours' => $hours && $hours->o ? substr($hours->o, 0, 5).' - '.substr($hours->c, 0, 5) : null, 'phone' => null,
                'onlineBookable' => $bookable || $tickets, 'onlineBooking' => $bookable, 'onlineTickets' => $tickets, 'sameDayAvailable' => $fresh, 'onlineNotice' => $notice,
            ];
        }

        return [
            'name' => $cfg['name'] ?: ($org->name ?? $site->name ?? '007 Resort & Spa'), 'siteName' => $site->name ?? null, 'currency' => 'NGN',
            'timezone' => $site->time_zone ?? config('booking.timezone'),
            'contact' => array_filter((array) $cfg['contact'], fn ($v) => filled($v)), 'openingHours' => $cfg['opening_hours'],
            'node' => Node::isCloud() ? 'cloud' : 'local',
            'siteAvailability' => ['localNodeFresh' => $fresh, 'immediateOrdering' => $fresh],
            'facilities' => $facilities,
        ];
    }
}
