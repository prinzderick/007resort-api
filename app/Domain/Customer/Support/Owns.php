<?php

namespace App\Domain\Customer\Support;

use App\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Strict ownership. Every `assert*` is a NO-OP for staff (their access is permission-based) and throws 404 for a customer who
 * does not own the thing (never 403: a foreign id must be indistinguishable from a missing one).
 */
final class Owns
{
    /** A guest (X-Order-Token) owns exactly the ONE booking / order / membership of its guest order. */
    public static function booking(?string $bookingCustomerId, ?string $bookingId = null): void
    {
        if (Actor::isStaff()) {
            return;
        }
        if (($g = Actor::guest()) !== null) {
            $bookingId !== null && $g->bookingId === strtolower($bookingId) ? null : throw Actor::notYours('Booking');

            return;
        }
        if ($bookingCustomerId === null || $bookingCustomerId !== Actor::customerId()) {
            throw Actor::notYours('Booking');
        }
    }

    public static function membership(?string $membershipCustomerId, ?string $membershipId = null): void
    {
        if (Actor::isStaff()) {
            return;
        }
        if (($g = Actor::guest()) !== null) {
            $membershipId !== null && $g->membershipId === strtolower($membershipId) ? null : throw Actor::notYours('Membership');

            return;
        }
        if ($membershipCustomerId === null || $membershipCustomerId !== Actor::customerId()) {
            throw Actor::notYours('Membership');
        }
    }

    public static function order(string $orderId): void
    {
        if (! Actor::isStaff() && ! self::orderOwned($orderId)) {
            throw Actor::notYours('Order');
        }
    }

    public static function orderOwned(string $orderId, ?string $customerId = null): bool
    {
        if ($customerId === null && ($g = Actor::guest()) !== null) {
            return $g->orderId !== null && $g->orderId === strtolower($orderId);
        }
        $cid = $customerId ?? Actor::customerId();

        return $cid !== null && Ids::isUuid($orderId) && DB::table('customer_order')->where('order_id', Ids::toBinary($orderId))->where('customer_id', Ids::toBinary($cid))->exists();
    }

    /** Entitlement belongs to me directly, through my booking, or through my order. */
    public static function entitlement(object $e): void
    {
        if (Actor::isStaff()) {
            return;
        }
        if (($g = Actor::guest()) !== null) {
            $ok = ($g->bookingId !== null && ($e->booking_id ?? null) === $g->bookingId) || ($g->orderId !== null && ($e->order_id ?? null) === $g->orderId);
            $ok || throw Actor::notYours('Entitlement');

            return;
        }
        $me = Actor::customerId() ?? throw Actor::notYours('Entitlement');
        $ok = ($e->customer_id ?? null) === $me
            || (($e->booking_id ?? null) !== null && DB::table('booking')->where('id', Ids::toBinary($e->booking_id))->where('customer_id', Ids::toBinary($me))->exists())
            || (($e->order_id ?? null) !== null && self::orderOwned($e->order_id, $me));
        if (! $ok) {
            throw Actor::notYours('Entitlement');
        }
    }

    /** A Paystack payment row (DB object): via its booking / membership subject, or the orders in its intent. */
    public static function payment(object $p): void
    {
        if (Actor::isStaff()) {
            return;
        }
        if (($g = Actor::guest()) !== null) {
            $intent = json_decode((string) $p->intent, true) ?: [];
            $orders = array_map(fn ($a) => (string) ($a['orderId'] ?? ''), (array) ($intent['allocations'] ?? []));
            $ok = match ($p->subject_type) {
                'BOOKING' => $p->subject_id !== null && $g->bookingId === Ids::fromBinary($p->subject_id),
                'MEMBERSHIP' => $p->subject_id !== null && $g->membershipId === Ids::fromBinary($p->subject_id),
                default => $g->orderId !== null && $orders !== [] && collect($orders)->every(fn ($o) => $o === $g->orderId),
            };
            $ok || throw Actor::notYours('Payment');

            return;
        }
        $me = Actor::customerId() ?? throw Actor::notYours('Payment');
        $meBin = Ids::toBinary($me);
        $ok = false;
        if ($p->subject_type === 'BOOKING' && $p->subject_id !== null) {
            $ok = DB::table('booking')->where('id', $p->subject_id)->where('customer_id', $meBin)->exists();
        } elseif ($p->subject_type === 'MEMBERSHIP' && $p->subject_id !== null) {
            $ok = DB::table('membership')->where('id', $p->subject_id)->where('customer_id', $meBin)->exists();
        } else {
            $intent = json_decode((string) $p->intent, true) ?: [];
            $orders = array_map(fn ($a) => (string) ($a['orderId'] ?? ''), (array) ($intent['allocations'] ?? []));
            $ok = $orders !== [] && collect($orders)->every(fn ($o) => self::orderOwned($o, $me));
        }
        if (! $ok) {
            throw Actor::notYours('Payment');
        }
    }
}
