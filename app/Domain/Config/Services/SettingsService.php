<?php

namespace App\Domain\Config\Services;

use App\Domain\Config\Support\ConfigChange;
use App\Domain\Organization\Services\TaxSettingService;
use App\Support\Api\Concurrency;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/** Business profile, receipt settings and per-facility payment methods. */
class SettingsService
{
    public const METHODS = ['CASH' => 'Cash', 'CARD' => 'Card (card reader)', 'TRANSFER' => 'Bank transfer', 'POS_TERMINAL' => 'POS terminal', 'PAYSTACK' => 'Paystack (online)'];

    /** Methods a staff payment can use at a facility (PAYSTACK is the online channel and is not gated per facility yet). */
    public const STAFF_TENDERS = ['CASH', 'CARD', 'TRANSFER', 'POS_TERMINAL'];

    public function __construct(private readonly FacilityLoader $facilities) {}

    // ---- business profile ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function business(): array
    {
        $site = DB::table('site')->where('id', Ids::toBinary((string) Tenant::siteId()))->first() ?? throw ApiProblem::notFound('not_found', 'No site is configured on this node.');
        $org = DB::table('organization')->where('id', $site->organization_id)->first();

        return ['organizationName' => $org->name, 'siteName' => $site->name, 'timezone' => $site->time_zone, 'currency' => $site->currency ?? 'NGN', 'address' => $site->address, 'phone' => $site->phone,
            'email' => $site->email, 'rowVersion' => (int) $site->row_version];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function updateBusiness(array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($in, $ifMatch): array {
            $site = DB::table('site')->where('id', Ids::toBinary((string) Tenant::siteId()))->lockForUpdate()->first() ?? throw ApiProblem::notFound('not_found', 'No site is configured on this node.');
            $org = DB::table('organization')->where('id', $site->organization_id)->lockForUpdate()->first();
            Concurrency::assertVersion((int) $site->row_version, $ifMatch, 'business profile');
            $old = $this->business();
            $siteSet = [];
            foreach (['siteName' => 'name', 'timezone' => 'time_zone', 'address' => 'address', 'phone' => 'phone', 'email' => 'email'] as $k => $col) {
                if (array_key_exists($k, $in) && $in[$k] !== $site->{$col}) {
                    $siteSet[$col] = $in[$k];
                }
            }
            $orgChanged = array_key_exists('organizationName', $in) && $in['organizationName'] !== $org->name;
            if ($siteSet === [] && ! $orgChanged) {
                return $old;
            }
            if ($orgChanged) {
                DB::table('organization')->where('id', $org->id)->update(['name' => $in['organizationName'], 'row_version' => $org->row_version + 1]);
            }
            $version = (int) $site->row_version + 1;
            DB::table('site')->where('id', $site->id)->update($siteSet + ['row_version' => $version]);
            $new = $this->business();
            $changed = array_keys(array_filter($new, fn ($v, $k) => $k !== 'rowVersion' && $v !== $old[$k], ARRAY_FILTER_USE_BOTH));
            ConfigChange::record('config.business.update', 'BusinessProfile', Ids::fromBinary($site->id), array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)),
                'businessProfile', array_intersect_key($new, array_flip($changed)), $version, organizationId: Ids::fromBinary($site->organization_id), siteId: Ids::fromBinary($site->id));

            return $new;
        });
    }

    // ---- receipt settings ------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        $org = (string) Tenant::organizationId();
        $r = DB::table('receipt_setting')->where('organization_id', Ids::toBinary($org))->first();
        $tax = app(TaxSettingService::class)->get($org);
        $orgName = DB::table('organization')->where('id', Ids::toBinary($org))->value('name');

        return [
            'businessName' => $r->business_name ?? null, 'address' => $r->address ?? null, 'phone' => $r->phone ?? null, 'headerNote' => $r->header_note ?? null, 'footer' => $r->footer ?? null,
            'logoUrl' => $r->logo_url ?? null, 'showTin' => (bool) ($r->show_tin ?? true), 'paperColumns' => (int) ($r->paper_columns ?? config('payments.receipt.columns', 48)),
            'tin' => $tax['vatNumber'], 'vatRegistered' => $tax['vatEnabled'],
            'effective' => ['businessName' => (string) ($r->business_name ?? null ?: config('payments.receipt.business_name') ?: $orgName), 'address' => (string) ($r->address ?? null ?: config('payments.receipt.site_address', '')),
                'footer' => (string) ($r->footer ?? null ?: config('payments.receipt.footer', ''))],
            'rowVersion' => (int) ($r->row_version ?? 0),
        ];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function updateReceipt(array $in, ?int $ifMatch): array
    {
        return DB::transaction(function () use ($in, $ifMatch): array {
            $org = (string) Tenant::organizationId();
            $bin = Ids::toBinary($org);
            $row = DB::table('receipt_setting')->where('organization_id', $bin)->lockForUpdate()->first();
            Concurrency::assertVersion((int) ($row->row_version ?? 0), $ifMatch, 'receipt settings');
            $old = $this->receipt();
            $map = ['businessName' => 'business_name', 'address' => 'address', 'phone' => 'phone', 'headerNote' => 'header_note', 'footer' => 'footer', 'logoUrl' => 'logo_url', 'showTin' => 'show_tin', 'paperColumns' => 'paper_columns'];
            $set = [];
            foreach ($map as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $set[$col] = is_bool($in[$k]) ? (int) $in[$k] : $in[$k];
                }
            }
            if ($set === []) {
                return $old;
            }
            if ($row === null) {
                DB::table('receipt_setting')->insert(['organization_id' => $bin, 'row_version' => 1] + $set);
                $version = 1;
            } else {
                $version = (int) $row->row_version + 1;
                DB::table('receipt_setting')->where('organization_id', $bin)->update($set + ['row_version' => $version]);
            }
            $new = $this->receipt();
            $changed = array_keys(array_filter($in, fn ($v, $k) => ($old[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
            if ($changed === [] && $row !== null) {
                DB::table('receipt_setting')->where('organization_id', $bin)->update(['row_version' => $row->row_version]);

                return $old;
            }
            ConfigChange::record('config.receipt.update', 'ReceiptSetting', $org, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)),
                'receiptSetting', array_intersect_key($new, array_flip($changed)) + ['organizationId' => $org], $version);

            return $new;
        });
    }

    /** Effective receipt settings as the receipt renderer needs them (config/env fallbacks applied). @return array<string, mixed> */
    public static function forReceipt(string $organizationId): array
    {
        $r = DB::table('receipt_setting')->where('organization_id', Ids::toBinary($organizationId))->first();

        return ['businessName' => $r->business_name ?? null, 'address' => $r->address ?? null, 'phone' => $r->phone ?? null, 'headerNote' => $r->header_note ?? null, 'footer' => $r->footer ?? null,
            'logoUrl' => $r->logo_url ?? null, 'showTin' => (bool) ($r->show_tin ?? true), 'paperColumns' => $r === null ? null : (int) $r->paper_columns];
    }

    // ---- payment methods per facility ------------------------------------------------------------------------------------------

    /** @return array{facilityId: string, version: int, restricted: bool, methods: array<string, bool>, labels: array<string, string>} */
    public function paymentMethods(string $facilityId): array
    {
        $f = $this->facilities->find($facilityId);
        $rows = DB::table('facility_payment_method')->where('facility_unit_id', $f->id)->pluck('is_enabled', 'method');
        $methods = [];
        foreach (self::METHODS as $m => $label) {
            $methods[$m] = $rows->isEmpty() ? true : (bool) ($rows[$m] ?? false);
        }

        return ['facilityId' => Ids::fromBinary($f->id), 'version' => (int) $f->row_version, 'restricted' => $rows->isNotEmpty(), 'methods' => $methods, 'labels' => self::METHODS];
    }

    /** @param array<string, bool> $methods @return array<string, mixed> */
    public function setPaymentMethods(string $facilityId, array $methods, ?int $ifMatch): array
    {
        $errors = [];
        foreach ($methods as $m => $v) {
            if (! isset(self::METHODS[$m])) {
                $errors["methods.{$m}"] = ['Unknown payment method. One of '.implode(', ', array_keys(self::METHODS)).'.'];
            } elseif (! is_bool($v)) {
                $errors["methods.{$m}"] = ['Must be true or false.'];
            }
        }
        if ($errors !== []) {
            throw ApiProblem::unprocessable('validation_failed', 'Invalid payment methods.', $errors);
        }

        return DB::transaction(function () use ($facilityId, $methods, $ifMatch): array {
            $f = $this->facilities->lock($facilityId);
            Concurrency::assertVersion((int) $f->row_version, $ifMatch, 'facility configuration');
            $old = $this->paymentMethods($facilityId);
            $target = array_replace($old['methods'], $methods);
            $acceptsPayments = DB::table('facility_capability')->where('facility_unit_id', $f->id)->where('capability_code', 'PAYMENT_ACCEPTANCE')->where('is_enabled', 1)->exists();
            if ($acceptsPayments && ! array_filter(array_intersect_key($target, array_flip(self::STAFF_TENDERS)))) {
                throw ApiProblem::unprocessable('validation_failed', 'At least one payment method must stay enabled where payments are accepted.', ['methods' => ['Enable at least one of CASH, CARD, TRANSFER, POS_TERMINAL.']]);
            }
            $allOn = ! in_array(false, $target, true);
            if ($target === $old['methods'] && ($allOn || $old['restricted'])) {
                return $old;
            }
            DB::table('facility_payment_method')->where('facility_unit_id', $f->id)->delete();
            if (! $allOn) {
                foreach ($target as $m => $on) {
                    DB::table('facility_payment_method')->insert(['facility_unit_id' => $f->id, 'method' => $m, 'is_enabled' => $on ? 1 : 0]);
                }
            }
            $version = (int) $f->row_version + 1;
            DB::table('facility_unit')->where('id', $f->id)->update(['row_version' => $version]);
            ConfigChange::record('config.payment_methods.update', 'Facility', $facilityId, ['methods' => $old['methods']], ['methods' => $target], 'facilityPaymentMethods', ['methods' => $allOn ? null : $target], $version,
                facilityId: $facilityId, organizationId: Ids::fromBinary($f->organization_id), siteId: Ids::fromBinary($f->site_id));

            return $this->paymentMethods($facilityId);
        });
    }

    /** Runtime guard used by the payment service: is every tender type allowed at this facility? @param list<string> $tenderTypes */
    public function assertTendersAllowed(string $facilityId, array $tenderTypes): void
    {
        $rows = DB::table('facility_payment_method')->where('facility_unit_id', Ids::toBinary($facilityId))->pluck('is_enabled', 'method');
        if ($rows->isEmpty()) {
            return;
        }
        $errors = [];
        foreach ($tenderTypes as $i => $t) {
            if (! (bool) ($rows[$t] ?? false)) {
                $errors["tenders.{$i}.tenderType"] = [(self::METHODS[$t] ?? $t).' is not accepted at this facility.'];
            }
        }
        if ($errors !== []) {
            throw ApiProblem::unprocessable('payment_method_disabled', 'That payment method is switched off at this facility.', $errors);
        }
    }
}
