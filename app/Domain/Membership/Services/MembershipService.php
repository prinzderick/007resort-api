<?php

namespace App\Domain\Membership\Services;

use App\Domain\Guest\Support\GuestContact;
use App\Domain\Identity\Models\Customer;
use App\Domain\Membership\Models\MemberCard;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\MembershipPlan;
use App\Domain\Membership\Support\CardCodec;
use App\Support\Audit\Audit;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sell / renew / suspend / cancel memberships and manage cards. Every method is one DB transaction that writes the
 * change + membership_status_history + audit + outbox together. Money is decimal strings; a membership is only
 * ACTIVE once payment is settled (reception tenders, or PaymentCaptured -> activateOnPayment()).
 */
class MembershipService
{
    public function __construct(private readonly MembershipLifecycle $lifecycle, private readonly CustomerResolver $customers) {}

    /**
     * @param  array{name: string, phone?: ?string, email?: ?string}  $customer
     * @param  list<array{tenderType: string, amount: string, reference?: ?string}>  $tenders
     */
    public function purchase(string $planId, array $customer, ?string $facilityId, array $tenders, ?string $paystackReference, ?string $staffId, string $channel = 'RECEPTION', ?string $customerId = null, ?GuestContact $guest = null): Membership
    {
        $org = Tenant::organizationId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No organization in context.');
        $site = Tenant::siteId() ?? throw ApiProblem::badRequest('tenant_unresolved', 'No site in context.');

        $membershipId = DB::transaction(function () use ($planId, $customer, $tenders, $paystackReference, $staffId, $channel, $org, $site, $customerId, $guest): string {
            $plan = MembershipPlan::query()->where('organization_id', $org)->find($planId) ?? throw ApiProblem::notFound('plan_not_found', 'Membership plan not found.');
            if (! $plan->is_active) {
                throw ApiProblem::conflict('plan_inactive', 'This membership plan is no longer on sale.');
            }
            $settlement = $this->settlement($plan->price, $tenders);
            // Guest checkout: NO customer row (and never a match against existing customers/accounts): the holder is the contact snapshot.
            $holder = $guest !== null ? null : ($customerId !== null ? Customer::query()->where('organization_id', $org)->findOrFail($customerId) : $this->customers->resolve($org, $customer));

            $id = Ids::uuid7();
            $m = $this->insertMembership($id, $org, $site, $plan, $holder?->id, $staffId, $channel, $paystackReference ?? $settlement, $guest);
            Audit::record('membership.purchase', 'Membership', $id, null,
                ['number' => $m->number, 'planId' => $plan->id, 'customerId' => $holder?->id, 'guest' => $guest !== null, 'price' => $plan->price, 'currency' => $plan->currency, 'channel' => $channel, 'paid' => $settlement !== null]);
            Outbox::record('MembershipPurchased', 'Membership', $id, [
                'membershipId' => $id, 'number' => $m->number, 'planId' => $plan->id, 'customerId' => $holder?->id, 'customerName' => $holder?->full_name ?? $guest?->name,
                'price' => $plan->price, 'currency' => $plan->currency, 'channel' => $channel, 'soldByStaffId' => $staffId, 'paymentReference' => $paystackReference ?? $settlement,
            ], organizationId: $org, siteId: $site);
            $this->history($id, null, Membership::PENDING_PAYMENT, 'purchase', 'API', $staffId);

            if ($settlement !== null) {
                $this->activate($id, null, $settlement, 'reception tender settlement', 'API', $staffId);
            }

            return $id;
        });

        return $this->load($membershipId);
    }

    /** Called when a payment for this membership is captured (Payments listener). Idempotent per paymentId. */
    public function activateOnPayment(string $membershipId, string $paymentId, ?string $reference = null): Membership
    {
        DB::transaction(function () use ($membershipId, $paymentId, $reference): void {
            $already = DB::table('membership')->where('payment_id', Ids::toBinary($paymentId))->value('id');
            if ($already !== null) {
                return; // this payment was already applied (replayed event)
            }
            $status = DB::table('membership')->where('id', Ids::toBinary($membershipId))->lockForUpdate()->value('status')
                ?? throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
            if ($status === Membership::PENDING_PAYMENT) {
                $this->activate($membershipId, $paymentId, $reference, 'payment captured', 'PAYMENT', null);
            } elseif (in_array($status, [Membership::ACTIVE, Membership::PENDING_RENEWAL, Membership::EXPIRED], true)) {
                $this->applyRenewal($membershipId, $paymentId, $reference, 'renewal payment captured', 'PAYMENT', null);
            } else {
                throw ApiProblem::conflict('invalid_membership_transition', "A {$status} membership cannot take a payment.");
            }
        });

        return $this->load($membershipId);
    }

    /** @param list<array{tenderType: string, amount: string, reference?: ?string}> $tenders */
    public function renew(string $membershipId, array $tenders, ?string $staffId): Membership
    {
        DB::transaction(function () use ($membershipId, $tenders, $staffId): void {
            $m = Membership::query()->lockForUpdate()->find($membershipId) ?? throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
            $plan = MembershipPlan::query()->find($m->plan_id);
            if (! in_array($m->status, [Membership::ACTIVE, Membership::PENDING_RENEWAL, Membership::EXPIRED], true)) {
                throw ApiProblem::conflict('invalid_membership_transition', "A {$m->status} membership cannot be renewed.", ['from' => $m->status, 'to' => Membership::ACTIVE]);
            }
            if (! $plan->is_active) {
                throw ApiProblem::conflict('plan_inactive', 'This membership plan is no longer on sale.');
            }
            $settlement = $this->settlement($plan->price, $tenders);
            if ($settlement === null) {
                throw ApiProblem::unprocessable('payment_required', 'Renewal requires payment (tenders covering the plan price).');
            }
            $this->applyRenewal($membershipId, null, $settlement, 'renewed at reception', 'API', $staffId);
        });

        return $this->load($membershipId);
    }

    public function suspend(string $membershipId, string $reason, ?string $staffId): Membership
    {
        $this->lifecycle->transition($membershipId, Membership::SUSPENDED, $reason, 'API', $staffId, set: ['suspended_reason' => mb_substr($reason, 0, 255)]);

        return $this->load($membershipId);
    }

    public function reinstate(string $membershipId, string $reason, ?string $staffId): Membership
    {
        $current = DB::table('membership')->where('id', Ids::toBinary($membershipId))->value('status') ?? throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
        if ($current !== Membership::SUSPENDED) {
            throw ApiProblem::conflict('invalid_membership_transition', "A {$current} membership cannot be reinstated.", ['from' => $current, 'to' => Membership::ACTIVE]);
        }
        $this->lifecycle->transition($membershipId, Membership::ACTIVE, $reason, 'API', $staffId, expectFrom: [Membership::SUSPENDED], set: ['suspended_reason' => null]);

        return $this->load($membershipId);
    }

    public function cancel(string $membershipId, string $reason, ?string $staffId): Membership
    {
        $this->lifecycle->transition($membershipId, Membership::CANCELLED, $reason, 'API', $staffId, set: ['cancelled_reason' => mb_substr($reason, 0, 255)]);

        return $this->load($membershipId);
    }

    public function attachNfcCard(string $membershipId, string $uid, ?string $staffId): MemberCard
    {
        $uid = CardCodec::normalizeNfc($uid);
        if (strlen($uid) < 4) {
            throw ApiProblem::unprocessable('validation_failed', 'Invalid NFC uid.', [['field' => 'uid', 'code' => 'invalid', 'message' => 'NFC uid must be hexadecimal.']]);
        }

        return DB::transaction(function () use ($membershipId, $uid, $staffId): MemberCard {
            $m = Membership::query()->lockForUpdate()->find($membershipId) ?? throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
            try {
                $card = MemberCard::create(['membership_id' => $m->id, 'card_type' => MemberCard::NFC, 'identifier' => $uid, 'issued_at' => CarbonImmutable::now('UTC')]);
            } catch (UniqueConstraintViolationException) {
                throw ApiProblem::conflict('card_already_assigned', 'That NFC card is already assigned to a membership.');
            }
            Audit::record('membership.card.attach', 'Membership', $m->id, null, ['cardType' => 'NFC', 'identifier' => $uid], organizationId: $m->organization_id, siteId: $m->site_id, actorStaffId: $staffId);

            return $card;
        });
    }

    public function revokeCard(string $membershipId, string $cardId, string $status, ?string $staffId): MemberCard
    {
        return DB::transaction(function () use ($membershipId, $cardId, $status, $staffId): MemberCard {
            $card = MemberCard::query()->where('membership_id', $membershipId)->lockForUpdate()->find($cardId) ?? throw ApiProblem::notFound('card_not_found', 'Card not found.');
            if ($card->status !== 'ACTIVE') {
                return $card; // idempotent
            }
            $m = Membership::query()->find($membershipId);
            $card->update(['status' => $status, 'revoked_at' => CarbonImmutable::now('UTC')]);
            Audit::record('membership.card.revoke', 'Membership', $membershipId, ['cardId' => $cardId, 'status' => 'ACTIVE'], ['cardId' => $cardId, 'status' => $status],
                organizationId: $m->organization_id, siteId: $m->site_id, actorStaffId: $staffId);

            return $card->refresh();
        });
    }

    public function load(string $membershipId): Membership
    {
        return Membership::query()->with(['plan', 'customer', 'cards'])->find($membershipId) ?? throw ApiProblem::notFound('membership_not_found', 'Membership not found.');
    }

    // ---------------------------------------------------------------- internals

    /** Mark PENDING_PAYMENT -> ACTIVE and open the first term. Must run inside a transaction. */
    private function activate(string $membershipId, ?string $paymentId, ?string $reference, string $reason, string $source, ?string $staffId): void
    {
        $m = Membership::query()->find($membershipId);
        [$from, $until] = $this->term($m, CarbonImmutable::now('UTC'), $m->duration_days);
        $set = ['valid_from' => $from->format('Y-m-d H:i:s.u'), 'valid_until' => $until->format('Y-m-d H:i:s.u'), 'grace_until' => null];
        if ($paymentId !== null) {
            $set['payment_id'] = Ids::toBinary($paymentId);
        }
        if ($reference !== null) {
            $set['payment_reference'] = mb_substr($reference, 0, 128);
        }
        $this->lifecycle->transition($membershipId, Membership::ACTIVE, $reason, $source, $staffId, expectFrom: [Membership::PENDING_PAYMENT], set: $set);
    }

    /** Extend the term (from the end of the current term if still running, else from now), reset visits, snapshot current plan terms. */
    private function applyRenewal(string $membershipId, ?string $paymentId, ?string $reference, string $reason, string $source, ?string $staffId): void
    {
        $m = Membership::query()->find($membershipId);
        $plan = MembershipPlan::query()->find($m->plan_id);
        $now = CarbonImmutable::now('UTC');
        $base = ($m->valid_until !== null && CarbonImmutable::instance($m->valid_until) > $now) ? CarbonImmutable::instance($m->valid_until) : $now;
        [, $until] = $this->term($m, $base, $plan->duration_days);
        $set = [
            'valid_until' => $until->format('Y-m-d H:i:s.u'), 'grace_until' => null, 'visits_used' => 0, 'renewal_count' => $m->renewal_count + 1,
            'visit_limit' => $plan->visit_limit, 'guest_allowance' => $plan->guest_allowance, 'member_discount_percent' => $plan->member_discount_percent,
            'grace_period_days' => $plan->grace_period_days, 'duration_days' => $plan->duration_days,
            'price_paid' => $plan->price, 'currency' => $plan->currency,
        ];
        if ($m->valid_from === null || $base === $now) {
            $set['valid_from'] = $now->format('Y-m-d H:i:s.u');
        }
        if ($paymentId !== null) {
            $set['payment_id'] = Ids::toBinary($paymentId);
        }
        if ($reference !== null) {
            $set['payment_reference'] = mb_substr($reference, 0, 128);
        }
        $this->lifecycle->transition($membershipId, Membership::ACTIVE, $reason, $source, $staffId,
            expectFrom: [Membership::ACTIVE, Membership::PENDING_RENEWAL, Membership::EXPIRED], set: $set);
        Outbox::record('MembershipRenewed', 'Membership', $membershipId,
            ['membershipId' => $membershipId, 'validUntil' => $until->format('Y-m-d\TH:i:s.v\Z'), 'paymentReference' => $reference],
            entityVersion: (int) DB::table('membership')->where('id', Ids::toBinary($membershipId))->value('row_version'),
            organizationId: $m->organization_id, siteId: $m->site_id);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} term start and end (end = last instant of the site-local day) */
    private function term(Membership $m, CarbonImmutable $start, int $days): array
    {
        $tz = DB::table('site')->where('id', Ids::toBinary($m->site_id))->value('time_zone') ?: 'Africa/Lagos';
        $end = $start->setTimezone($tz)->addDays($days)->endOfDay()->utc();

        return [$start, $end];
    }

    /** @param list<array{tenderType: string, amount: string, reference?: ?string}> $tenders */
    private function settlement(string $price, array $tenders): ?string
    {
        if ($tenders === [] || ! config('membership.trust_reception_tenders')) {
            return null;
        }
        $sum = Money::zero();
        $parts = [];
        foreach ($tenders as $t) {
            $sum = $sum->add(Money::of($t['amount']));
            $parts[] = $t['tenderType'].(empty($t['reference']) ? '' : ':'.$t['reference']);
        }
        if (! $sum->equals(Money::of($price))) {
            throw ApiProblem::unprocessable('amount_mismatch', "Tenders total {$sum->amount} but the plan price is {$price}.");
        }

        return 'RECEPTION:'.implode('+', $parts);
    }

    private function insertMembership(string $id, string $org, string $site, MembershipPlan $plan, ?string $customerId, ?string $staffId, string $channel, ?string $reference, ?GuestContact $guest = null): Membership
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $m = Membership::create([
                    'id' => $id, 'organization_id' => $org, 'site_id' => $site, 'number' => CardCodec::newNumber(), 'plan_id' => $plan->id, 'customer_id' => $customerId,
                    'contact_name' => $guest?->name, 'contact_email' => $guest?->email, 'contact_phone' => $guest?->phone,
                    'status' => Membership::PENDING_PAYMENT, 'visit_limit' => $plan->visit_limit, 'guest_allowance' => $plan->guest_allowance,
                    'member_discount_percent' => $plan->member_discount_percent, 'grace_period_days' => $plan->grace_period_days, 'duration_days' => $plan->duration_days,
                    'price_paid' => $plan->price, 'currency' => $plan->currency, 'purchase_channel' => $channel, 'sold_by_staff_id' => $staffId,
                    'payment_reference' => $reference === null ? null : mb_substr($reference, 0, 128),
                ]);
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
        MemberCard::create(['membership_id' => $id, 'card_type' => MemberCard::QR, 'identifier' => CardCodec::qrToken($id), 'issued_at' => CarbonImmutable::now('UTC')]);
        MemberCard::create(['membership_id' => $id, 'card_type' => MemberCard::MEMBER_ID, 'identifier' => $m->number, 'issued_at' => CarbonImmutable::now('UTC')]);

        return $m;
    }

    private function history(string $id, ?string $from, string $to, string $reason, string $source, ?string $staffId): void
    {
        DB::table('membership_status_history')->insert([
            'id' => Ids::toBinary(Ids::uuid7()), 'membership_id' => Ids::toBinary($id), 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'source' => $source,
            'actor_staff_id' => $staffId === null ? null : Ids::toBinary($staffId), 'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }
}
