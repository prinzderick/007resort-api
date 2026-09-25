<?php

namespace App\Domain\Guest\Services;

use App\Domain\Booking\Http\Presenters\BookingPresenter;
use App\Domain\Booking\Models\Booking;
use App\Domain\Customer\Services\TicketOrderService;
use App\Domain\Membership\Models\Membership;
use App\Domain\Ticketing\Http\Presenters\EntitlementPresenter;
use App\Domain\Ticketing\Models\Entitlement;
use App\Support\Ids;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The read model of a guest order (docs/GUEST_CHECKOUT.md s4.3). */
class GuestOrderView
{
    public function __construct(private readonly TicketOrderService $orders) {}

    /** @return array<string, mixed> */
    public function present(object $go): array
    {
        $u = fn ($b) => $b === null ? null : Ids::fromBinary($b);
        $booking = $ticketOrder = $membership = null;
        $tickets = [];
        $status = 'UNKNOWN';
        $paid = false;
        $due = Money::of('0');
        $currency = 'NGN';

        if ($go->booking_id !== null) {
            $b = Booking::query()->with('resource')->find($u($go->booking_id));
            if ($b !== null) {
                $booking = BookingPresenter::booking($b);
                $status = $b->status;
                $due = Money::of($b->total)->sub(Money::of($b->amount_paid));
                $paid = ! Money::of($b->amount_paid)->isZero() && ($due->isZero() || $due->isNegative());
                $tickets = Entitlement::query()->with('items')->where('booking_id', $b->id)->orderBy('id')->get()->all();
            }
        } elseif ($go->order_id !== null) {
            $oid = $u($go->order_id);
            $ticketOrder = $this->orders->present($oid);
            $o = DB::table('order')->where('id', $go->order_id)->first();
            $due = Money::of($ticketOrder['amountDue']);
            $paid = (bool) $ticketOrder['paid'];
            $status = $o->status === 'VOIDED' ? 'CANCELLED' : ($paid ? 'PAID' : 'PENDING_PAYMENT');
            $currency = $o->currency;
            $tickets = Entitlement::query()->with('items')->where('order_id', $oid)->orderBy('id')->get()->all();
        } elseif ($go->membership_id !== null) {
            $m = Membership::query()->with(['plan', 'cards'])->find($u($go->membership_id));
            if ($m !== null) {
                $membership = $m->toApi(withCards: true);
                $status = $m->status;
                $paid = $m->status !== Membership::PENDING_PAYMENT;
                $due = $paid ? Money::of('0') : Money::of($m->price_paid);
                $currency = $m->currency;
            }
        }

        return [
            'reference' => $go->reference, 'kind' => $go->kind, 'status' => $status, 'paid' => $paid,
            'amountDue' => $due->isNegative() ? '0.0000' : $due->amount, 'currency' => $currency,
            'contact' => ['name' => $go->contact_name, 'email' => $go->contact_email, 'phone' => $go->contact_phone],
            'claimed' => $go->claimed_customer_id !== null,
            'createdAt' => CarbonImmutable::parse($go->created_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'booking' => $booking, 'ticketOrder' => $ticketOrder, 'membership' => $membership,
            'tickets' => $paid || $booking !== null ? array_map(fn (Entitlement $e) => EntitlementPresenter::entitlement($e), $tickets) : [],
        ];
    }
}
