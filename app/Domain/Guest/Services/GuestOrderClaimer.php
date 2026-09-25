<?php

namespace App\Domain\Guest\Services;

use App\Domain\Guest\Support\ContactNormalizer;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Attach past guest purchases to a real customer once (and only once) that customer's email is VERIFIED.
 * Called from the email-verification code, and by the social-login flow wherever it marks an email verified (emailVerified=true).
 * Safety net: the customer must own a customer_account whose login_email equals `$verifiedEmail` AND is marked verified in the DB,
 * so calling it with an unverified email is a no-op. Never matches on phone, never touches erased orders.
 */
class GuestOrderClaimer
{
    private const FMT = 'Y-m-d H:i:s.u';

    /** @return int number of guest orders claimed */
    public function claim(string $customerId, string $verifiedEmail): int
    {
        $email = ContactNormalizer::email($verifiedEmail);
        if ($email === null) {
            return 0;
        }
        $cust = Ids::toBinary($customerId);
        $customer = DB::table('customer')->where('id', $cust)->first(['organization_id']);
        $account = DB::table('customer_account')->where('customer_id', $cust)->whereNotNull('email_verified_at')->whereRaw('LOWER(login_email) = ?', [$email])->first(['id']);
        if ($customer === null || $account === null) {
            return 0; // unverified (or not this customer's email): never claim
        }

        return DB::transaction(function () use ($customer, $cust, $customerId, $email): int {
            $orders = DB::table('guest_order')->where('organization_id', $customer->organization_id)->where('contact_email', $email)
                ->whereNull('claimed_customer_id')->whereNull('erased_at')->lockForUpdate()->get();
            $now = now('UTC')->format(self::FMT);
            foreach ($orders as $o) {
                DB::table('guest_order')->where('id', $o->id)->update(['claimed_customer_id' => $cust, 'claimed_at' => $now]);
                if ($o->booking_id !== null) {
                    DB::table('booking')->where('id', $o->booking_id)->whereNull('customer_id')->update(['customer_id' => $cust]);
                    DB::table('entitlement')->where('booking_id', $o->booking_id)->whereNull('customer_id')->update(['customer_id' => $cust]);
                }
                if ($o->order_id !== null) {
                    DB::table('customer_order')->where('order_id', $o->order_id)->whereNull('customer_id')->update(['customer_id' => $cust]);
                    DB::table('entitlement')->where('order_id', $o->order_id)->whereNull('customer_id')->update(['customer_id' => $cust]);
                }
                if ($o->membership_id !== null) {
                    DB::table('membership')->where('id', $o->membership_id)->whereNull('customer_id')->update(['customer_id' => $cust]);
                }
                Audit::record('guest.order.claim', 'GuestOrder', Ids::fromBinary($o->id), ['claimed' => false], ['claimed' => true, 'customerId' => $customerId, 'reference' => $o->reference, 'kind' => $o->kind],
                    organizationId: Ids::fromBinary($o->organization_id));
            }

            return $orders->count();
        });
    }
}
