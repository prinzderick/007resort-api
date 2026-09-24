<?php

namespace App\Domain\Config\Services;

use App\Domain\Booking\Models\BookableResource;
use App\Domain\Booking\Services\BookingRules;
use App\Domain\Config\Support\ConfigChange;
use App\Domain\Config\Support\ConfigVersion;
use App\Support\Api\Concurrency;
use App\Support\Api\Fmt;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Booking configuration beyond resource CRUD: weekly schedule, blackouts, resource-level rule overrides. */
class BookingConfigService
{
    /** API key => [booking_rule column, [min, max], integer?] */
    public const RULES = [
        'holdTtlSeconds' => ['hold_ttl_seconds', [30, 86400], true], 'minNoticeMinutes' => ['min_notice_minutes', [0, 525600], true], 'maxAdvanceDays' => ['max_advance_days', [0, 730], true],
        'cancelCutoffMinutes' => ['cancel_cutoff_minutes', [0, 525600], true], 'cancelFeePercent' => ['cancel_fee_percent', [0, 100], false],
        'rescheduleCutoffMinutes' => ['reschedule_cutoff_minutes', [0, 525600], true], 'maxReschedules' => ['max_reschedules', [0, 20], true], 'earlyEntryMinutes' => ['early_entry_minutes', [0, 240], true],
    ];

    public function resource(string $id, bool $lock = false): object
    {
        $q = Ids::isUuid($id) ? DB::table('bookable_resource')->where('id', Ids::toBinary($id))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->whereNull('deleted_at') : null;
        if ($q === null) {
            throw ApiProblem::notFound('not_found', 'Bookable resource not found.');
        }
        $lock && $q->lockForUpdate();

        return $q->first() ?? throw ApiProblem::notFound('not_found', 'Bookable resource not found.');
    }

    // ---- schedule ---------------------------------------------------------------------------------------------------------

    /** @return array{windows: list<array<string, mixed>>, rowVersion: int} */
    public function schedule(string $resourceId): array
    {
        $r = $this->resource($resourceId);
        $rows = DB::table('availability_schedule')->where('resource_id', $r->id)->orderBy('day_of_week')->orderBy('open_time')->get();

        return ['windows' => $rows->map(fn ($w) => ['dayOfWeek' => (int) $w->day_of_week, 'open' => substr($w->open_time, 0, 5), 'close' => substr($w->close_time, 0, 5), 'validFrom' => $w->valid_from, 'validTo' => $w->valid_to])->all(), 'rowVersion' => (int) $r->row_version];
    }

    /** @param list<array<string, mixed>> $windows @return array{windows: list<array<string, mixed>>, rowVersion: int} */
    public function setSchedule(string $resourceId, array $windows, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($resourceId, $windows, $ifMatch): array {
            $r = $this->resource($resourceId, true);
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'bookable resource');
            $errors = [];
            foreach ($windows as $i => $w) {
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $w['open']) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $w['close']) || $w['close'] <= $w['open']) {
                    $errors["windows.{$i}"] = ['open/close must be HH:MM with close after open (use 24:00 for midnight).'];
                }
                if (isset($w['validFrom'], $w['validTo']) && $w['validTo'] < $w['validFrom']) {
                    $errors["windows.{$i}.validTo"] = ['validTo must not be before validFrom.'];
                }
            }
            foreach ($windows as $i => $a) {
                foreach ($windows as $j => $b) {
                    if ($j <= $i || $a['dayOfWeek'] !== $b['dayOfWeek']) {
                        continue;
                    }
                    if ($a['open'] < $b['close'] && $b['open'] < $a['close'] && $this->datesOverlap($a, $b)) {
                        $errors["windows.{$j}"] = ["Overlaps window {$i} on the same day."];
                    }
                }
            }
            if ($errors !== []) {
                throw ApiProblem::unprocessable('validation_failed', 'The schedule is invalid.', $errors);
            }
            $old = $this->schedule($resourceId)['windows'];
            DB::table('availability_schedule')->where('resource_id', $r->id)->delete();
            foreach ($windows as $w) {
                DB::table('availability_schedule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'resource_id' => $r->id, 'day_of_week' => $w['dayOfWeek'], 'open_time' => $w['open'].':00',
                    'close_time' => $w['close'] === '24:00' ? '23:59:59' : $w['close'].':00', 'valid_from' => $w['validFrom'] ?? null, 'valid_to' => $w['validTo'] ?? null]);
            }
            $new = $this->schedule($resourceId)['windows'];
            $version = (int) $r->row_version + 1;
            DB::table('bookable_resource')->where('id', $r->id)->update(['row_version' => $version]);
            $rid = Ids::fromBinary($r->id);
            ConfigChange::record('config.booking.schedule.set', 'BookableResource', $rid, ['windows' => $old], ['windows' => $new], 'resourceSchedule', ['windows' => $new], $version, facilityId: Ids::fromBinary($r->facility_unit_id),
                organizationId: Ids::fromBinary($r->organization_id), siteId: Ids::fromBinary($r->site_id));

            return ['windows' => $new, 'rowVersion' => $version];
        });
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function datesOverlap(array $a, array $b): bool
    {
        $af = $a['validFrom'] ?? '0000-01-01';
        $at = $a['validTo'] ?? '9999-12-31';
        $bf = $b['validFrom'] ?? '0000-01-01';
        $bt = $b['validTo'] ?? '9999-12-31';

        return $af <= $bt && $bf <= $at;
    }

    // ---- blackouts --------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function blackoutView(object $b): array
    {
        return ['id' => Ids::fromBinary($b->id), 'resourceId' => Fmt::u($b->resource_id), 'facilityId' => Fmt::u($b->facility_unit_id), 'start' => Fmt::ts($b->starts_at), 'end' => Fmt::ts($b->ends_at), 'reason' => $b->reason];
    }

    /** @return list<array<string, mixed>> */
    public function blackouts(string $resourceId, ?string $from, ?string $to): array
    {
        $r = $this->resource($resourceId);
        $q = DB::table('blackout')->where(fn ($w) => $w->where('resource_id', $r->id)->orWhere('facility_unit_id', $r->facility_unit_id))->orderBy('starts_at');
        if ($from !== null && ($f = Fmt::clientTs($from)) !== null) {
            $q->where('ends_at', '>', $f);
        }
        if ($to !== null && ($t = Fmt::clientTs($to)) !== null) {
            $q->where('starts_at', '<', $t);
        }

        return $q->get()->map(fn ($b) => $this->blackoutView($b))->all();
    }

    /** @param array<string, mixed> $in facilityId, start, end, reason? @return array<string, mixed> */
    public function createFacilityBlackout(array $in): array
    {
        return DB::transaction(function () use ($in): array {
            $f = DB::table('facility_unit')->where('id', Ids::toBinary($in['facilityId']))->where('site_id', Ids::toBinary((string) Tenant::siteId()))->whereNull('deleted_at')->first()
                ?? throw ApiProblem::notFound('not_found', 'Facility was not found.');
            $id = Ids::uuid7();
            DB::table('blackout')->insert(['id' => Ids::toBinary($id), 'organization_id' => $f->organization_id, 'facility_unit_id' => $f->id, 'starts_at' => CarbonImmutable::parse($in['start'])->utc()->format('Y-m-d H:i:s.u'),
                'ends_at' => CarbonImmutable::parse($in['end'])->utc()->format('Y-m-d H:i:s.u'), 'reason' => $in['reason'] ?? null, 'created_by' => RequestContext::staffId() ? Ids::toBinary(RequestContext::staffId()) : null]);
            $v = $this->blackoutView(DB::table('blackout')->where('id', Ids::toBinary($id))->first());
            ConfigChange::record('config.booking.blackout.create', 'Blackout', $id, null, $v, 'blackout', $v + ['organizationId' => Ids::fromBinary($f->organization_id)], ConfigVersion::next($id),
                facilityId: Ids::fromBinary($f->id), organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return $v;
        });
    }

    public function deleteBlackout(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $b = Ids::isUuid($id) ? DB::table('blackout as b')->leftJoin('bookable_resource as r', 'r.id', '=', 'b.resource_id')->leftJoin('facility_unit as f', 'f.id', '=', 'b.facility_unit_id')
                ->where('b.id', Ids::toBinary($id))->where(fn ($w) => $w->where('r.site_id', Ids::toBinary((string) Tenant::siteId()))->orWhere('f.site_id', Ids::toBinary((string) Tenant::siteId())))->lockForUpdate()->first(['b.*']) : null;
            $b ?? throw ApiProblem::notFound('not_found', 'Blackout was not found.');
            $v = $this->blackoutView($b);
            DB::table('blackout')->where('id', $b->id)->delete();
            $fac = $b->facility_unit_id ?? DB::table('bookable_resource')->where('id', $b->resource_id)->value('facility_unit_id');
            Audit::record('config.booking.blackout.delete', 'Blackout', $id, $v, ['deleted' => true], facilityUnitId: Fmt::u($fac));
            Outbox::record('ConfigurationUpdated', 'Blackout', $id, ['domain' => 'blackout', 'changes' => ['deleted' => true]], ConfigVersion::next($id), facilityId: Fmt::u($fac));
        });
    }

    // ---- resource-level rules ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function rules(string $resourceId): array
    {
        $r = $this->resource($resourceId);
        $row = DB::table('booking_rule')->where('resource_id', $r->id)->first();
        $out = [];
        foreach (self::RULES as $key => [$col, , $int]) {
            $v = $row?->{$col};
            $out[$key] = $v === null ? null : ($int ? (int) $v : (float) $v);
        }
        $model = BookableResource::query()->find(Ids::fromBinary($r->id));
        $eff = app(BookingRules::class)->for($model);
        $effective = [];
        foreach (self::RULES as $key => [$col, , $int]) {
            $effective[$key] = $int ? (int) $eff[$col] : (float) $eff[$col];
        }

        return $out + ['effective' => $effective, 'rowVersion' => (int) $r->row_version];
    }

    /** @param array<string, mixed> $in full replacement; absent/null = inherit @return array<string, mixed> */
    public function setRules(string $resourceId, array $in, ?int $ifMatch): array
    {
        DB::transaction(function () use ($resourceId, $in, $ifMatch): void {
            $r = $this->resource($resourceId, true);
            Concurrency::assertVersion((int) $r->row_version, $ifMatch, 'bookable resource');
            $old = $this->rules($resourceId);
            $vals = [];
            foreach (self::RULES as $key => [$col]) {
                $vals[$col] = $in[$key] ?? null;
            }
            $existing = DB::table('booking_rule')->where('resource_id', $r->id)->first(['id']);
            if (array_filter($vals, fn ($v) => $v !== null) === []) {
                $existing && DB::table('booking_rule')->where('id', $existing->id)->delete();
            } elseif ($existing) {
                DB::table('booking_rule')->where('id', $existing->id)->update($vals);
            } else {
                DB::table('booking_rule')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'organization_id' => $r->organization_id, 'resource_id' => $r->id] + $vals);
            }
            app(BookingRules::class)->forget();
            $new = $this->rules($resourceId);
            if (json_encode(array_intersect_key($old, self::RULES)) === json_encode(array_intersect_key($new, self::RULES))) {
                return;
            }
            $version = (int) $r->row_version + 1;
            DB::table('bookable_resource')->where('id', $r->id)->update(['row_version' => $version]);
            $rid = Ids::fromBinary($r->id);
            $flat = fn (array $a) => array_intersect_key($a, self::RULES);
            ConfigChange::record('config.booking.rules.set', 'BookableResource', $rid, $flat($old), $flat($new), 'resourceRules', ['rules' => $flat($new)], $version, facilityId: Ids::fromBinary($r->facility_unit_id),
                organizationId: Ids::fromBinary($r->organization_id), siteId: Ids::fromBinary($r->site_id));
        });

        return $this->rules($resourceId);
    }
}
