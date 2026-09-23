<?php

namespace App\Domain\Membership\Models;

use App\Domain\Identity\Models\Customer;
use App\Support\Database\HasUuidV7;
use App\Support\Database\Model;
use App\Support\Money\MoneyString;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    use HasUuidV7;

    public const PENDING_PAYMENT = 'PENDING_PAYMENT';

    public const ACTIVE = 'ACTIVE';

    public const EXPIRED = 'EXPIRED';

    public const SUSPENDED = 'SUSPENDED';

    public const CANCELLED = 'CANCELLED';

    public const PENDING_RENEWAL = 'PENDING_RENEWAL';

    /** Allowed status transitions (from => [to...]). Everything else is rejected by MembershipLifecycle. */
    public const TRANSITIONS = [
        self::PENDING_PAYMENT => [self::ACTIVE, self::CANCELLED],
        self::ACTIVE => [self::PENDING_RENEWAL, self::EXPIRED, self::SUSPENDED, self::CANCELLED, self::ACTIVE],
        self::PENDING_RENEWAL => [self::ACTIVE, self::EXPIRED, self::SUSPENDED, self::CANCELLED],
        self::SUSPENDED => [self::ACTIVE, self::CANCELLED],
        self::EXPIRED => [self::ACTIVE, self::CANCELLED],
        self::CANCELLED => [],
    ];

    protected $table = 'membership';

    protected array $uuidColumns = ['organization_id', 'site_id', 'plan_id', 'customer_id', 'payment_id', 'sold_by_staff_id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'grace_until' => 'datetime',
            'price_paid' => MoneyString::class,
            'visits_used' => 'integer',
            'visit_limit' => 'integer',
            'guest_allowance' => 'integer',
            'grace_period_days' => 'integer',
            'duration_days' => 'integer',
            'renewal_count' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(MemberCard::class, 'membership_id');
    }

    /** Usable for entry at $now (status ACTIVE/PENDING_RENEWAL and inside the term or its grace window). */
    public function isUsableAt(CarbonImmutable $now): bool
    {
        if (! in_array($this->status, [self::ACTIVE, self::PENDING_RENEWAL], true) || $this->valid_until === null) {
            return false;
        }
        if ($now <= $this->valid_until) {
            return true;
        }

        return $this->grace_until !== null && $now <= $this->grace_until;
    }

    /** @return array<string, mixed> */
    public function toApi(bool $withCards = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $qr = $this->relationLoaded('cards') ? $this->cards->firstWhere('card_type', 'QR') : null;
        $out = [
            'id' => $this->id,
            'number' => $this->number,
            'planId' => $this->plan_id,
            'planName' => $this->relationLoaded('plan') ? $this->plan?->name : null,
            'customerId' => $this->customer_id,
            'holderName' => $this->relationLoaded('customer') ? $this->customer?->full_name : null,
            'status' => $this->status,
            'validFrom' => $this->fmt($this->valid_from),
            'validUntil' => $this->fmt($this->valid_until),
            'graceUntil' => $this->fmt($this->grace_until),
            'inGrace' => $this->valid_until !== null && $now > $this->valid_until && $this->grace_until !== null && $now <= $this->grace_until,
            'visitsUsed' => $this->visits_used,
            'visitLimit' => $this->visit_limit,
            'visitsRemaining' => $this->visit_limit === null ? null : max(0, $this->visit_limit - $this->visits_used),
            'guestAllowance' => $this->guest_allowance,
            'memberDiscountPercent' => number_format((float) $this->discountPercentString(), 2, '.', ''),
            'pricePaid' => $this->price_paid,
            'currency' => $this->currency,
            'renewalCount' => $this->renewal_count,
            'rowVersion' => (int) $this->row_version,
            'qrToken' => $qr?->identifier,
        ];
        if ($withCards) {
            $out['cards'] = $this->cards->map(fn (MemberCard $c) => $c->toApi())->values()->all();
        }

        return $out;
    }

    public function discountPercentString(): string
    {
        return (string) $this->getRawOriginal('member_discount_percent') ?: '0';
    }

    private function fmt(?\DateTimeInterface $d): ?string
    {
        return $d === null ? null : CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
