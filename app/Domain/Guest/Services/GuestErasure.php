<?php

namespace App\Domain\Guest\Services;

use App\Domain\Guest\Support\ContactNormalizer;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Right-to-erasure for guest contact data (NDPR). Anonymises the contact snapshots and revokes access; KEEPS financial records
 * (payments, receipts, ledger, order totals - including `payment.customer_email`, which is part of the immutable ledger).
 */
class GuestErasure
{
    private const FMT = 'Y-m-d H:i:s.u';

    public const ERASED = 'Erased guest';

    /**
     * @param  array{email?: ?string, phone?: ?string, reference?: ?string}  $by
     * @return array{guestOrders: int, bookings: int, orders: int, memberships: int, entitlements: int}
     */
    public function erase(array $by): array
    {
        $email = isset($by['email']) ? ContactNormalizer::email($by['email']) : null;
        $phone = isset($by['phone']) ? ContactNormalizer::phone($by['phone']) : null;
        $ref = isset($by['reference']) ? strtoupper(trim((string) $by['reference'])) : null;
        if ($email === null && $phone === null && ($ref === null || $ref === '')) {
            throw ApiProblem::unprocessable('validation_failed', 'Give an email, a phone or a guest order reference.', ['email' => ['one of email, phone, reference is required']]);
        }
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');

        return DB::transaction(function () use ($email, $phone, $ref, $org): array {
            $q = DB::table('guest_order')->where('organization_id', Ids::toBinary($org))->whereNull('erased_at');
            $q->where(function ($w) use ($email, $phone, $ref): void {
                $email !== null && $w->orWhere('contact_email', $email);
                $phone !== null && $w->orWhere('contact_phone', $phone);
                $ref !== null && $ref !== '' && $w->orWhere('reference', $ref);
            });
            $orders = $q->lockForUpdate()->get();
            $now = now('UTC')->format(self::FMT);
            $counts = ['guestOrders' => 0, 'bookings' => 0, 'orders' => 0, 'memberships' => 0, 'entitlements' => 0];
            $emails = [];
            foreach ($orders as $o) {
                $counts['guestOrders']++;
                $emails[] = (string) $o->contact_email;
                if ($o->booking_id !== null) {
                    $counts['bookings'] += DB::table('booking')->where('id', $o->booking_id)->whereNull('customer_id')->update(['customer_name' => self::ERASED, 'customer_email' => null, 'customer_phone' => null]);
                    $counts['entitlements'] += DB::table('entitlement')->where('booking_id', $o->booking_id)->whereNull('customer_id')->update(['holder_name' => self::ERASED]);
                }
                if ($o->order_id !== null) {
                    $counts['orders'] += DB::table('order')->where('id', $o->order_id)->update(['customer_name' => self::ERASED]);
                    DB::table('customer_order')->where('order_id', $o->order_id)->whereNull('customer_id')->update(['contact_name' => self::ERASED, 'contact_email' => null, 'contact_phone' => null]);
                    $counts['entitlements'] += DB::table('entitlement')->where('order_id', $o->order_id)->whereNull('customer_id')->update(['holder_name' => self::ERASED]);
                }
                if ($o->membership_id !== null) {
                    $counts['memberships'] += DB::table('membership')->where('id', $o->membership_id)->whereNull('customer_id')->update(['contact_name' => self::ERASED, 'contact_email' => null, 'contact_phone' => null]);
                }
                DB::table('guest_access_token')->where('guest_order_id', $o->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
                DB::table('guest_message')->where('guest_order_id', $o->id)->where('status', 'QUEUED')->update(['status' => 'CANCELLED']);
                DB::table('guest_order')->where('id', $o->id)->update(['contact_name' => self::ERASED, 'contact_email' => null, 'contact_phone' => null, 'client_ip_hash' => null, 'erased_at' => $now]);
                Audit::record('guest.order.erase', 'GuestOrder', Ids::fromBinary($o->id), null, ['reference' => $o->reference, 'kind' => $o->kind], organizationId: $org);
            }
            $contactIds = $orders->pluck('guest_contact_id')->filter()->unique()->all();
            $del = DB::table('guest_contact')->where('organization_id', Ids::toBinary($org))->where(function ($w) use ($email, $phone, $contactIds): void {
                $email !== null && $w->orWhere('email', $email);
                $phone !== null && $w->orWhere('phone', $phone);
                $contactIds !== [] && $w->orWhereIn('id', $contactIds);
            });
            // guest_order.guest_contact_id keeps an FK: detach first, then delete the contact rows.
            $ids = (clone $del)->pluck('id')->all();
            if ($ids !== []) {
                DB::table('guest_order')->whereIn('guest_contact_id', $ids)->update(['guest_contact_id' => null]);
                DB::table('guest_contact')->whereIn('id', $ids)->delete();
            }
            Audit::record('guest.erasure', 'GuestContact', Ids::uuid7(), null, $counts + ['key' => ContactNormalizer::fingerprint($email ?? $phone ?? $ref), 'contactRows' => count($ids)], organizationId: $org);

            return $counts;
        });
    }
}
