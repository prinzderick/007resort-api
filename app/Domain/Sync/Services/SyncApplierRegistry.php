<?php

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Contracts\SyncApplier;
use App\Domain\Sync\Support\ApplyResult;
use App\Domain\Sync\Support\InboundEvent;
use Closure;
use InvalidArgumentException;

/**
 * event_type -> SyncApplier. Modules register theirs from their service provider:
 *
 *   $this->callAfterResolving(SyncApplierRegistry::class, function (SyncApplierRegistry $registry) {
 *       $registry->register('OnlineBookingCreated', OnlineBookingCreatedApplier::class);      // resolved lazily via the container
 *       $registry->register(['StockReceived', 'StockAdjusted'], new StockApplier);
 *       $registry->register('HeartbeatAck', fn (InboundEvent $e) => ApplyResult::applied());  // closures work too
 *   });
 *
 * (`callAfterResolving` makes registration independent of provider boot order.) One applier per event type;
 * registering a type twice is a programming error and throws. An event type with no applier is stored and
 * marked FAILED by the inbox (never dropped) and heals once the applier ships (`r007:sync:replay-failed`).
 */
final class SyncApplierRegistry
{
    /** @var array<string, SyncApplier|Closure|class-string<SyncApplier>> */
    private array $appliers = [];

    /** @param string|list<string> $eventTypes */
    public function register(string|array $eventTypes, SyncApplier|Closure|string $applier): void
    {
        foreach ((array) $eventTypes as $type) {
            if (isset($this->appliers[$type])) {
                throw new InvalidArgumentException("A sync applier is already registered for event type '{$type}'.");
            }
            $this->appliers[$type] = $applier;
        }
    }

    public function has(string $eventType): bool
    {
        return isset($this->appliers[$eventType]);
    }

    public function for(string $eventType): ?SyncApplier
    {
        $a = $this->appliers[$eventType] ?? null;
        if ($a === null) {
            return null;
        }
        if (is_string($a)) {
            $a = app($a);
            $this->appliers[$eventType] = $a;
        }
        if ($a instanceof Closure) {
            $fn = $a;

            return new class($fn) implements SyncApplier
            {
                public function __construct(private readonly Closure $fn) {}

                public function apply(InboundEvent $event): ApplyResult
                {
                    return ($this->fn)($event);
                }
            };
        }

        return $a;
    }

    /** @return list<string> */
    public function types(): array
    {
        $t = array_keys($this->appliers);
        sort($t);

        return $t;
    }
}
