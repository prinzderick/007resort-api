<?php

namespace Tests\Support;

use App\Domain\Sync\Services\InboxProcessor;
use App\Domain\Sync\Services\SyncApplierRegistry;
use App\Domain\Sync\Support\InboundEvent;

/** Child-process bodies for the sync concurrency tests (run by Tests\Support\Concurrent). */
final class SyncWorkers
{
    /** Process N delivers event N of the list (all released at the same instant). @param list<array<string, mixed>> $events */
    public function deliverNth(int $index, array $events): string
    {
        return $this->deliver($index, $events[$index]);
    }

    /** @param array<string, mixed> $envelope */
    public function deliver(int $index, array $envelope): string
    {
        $registry = app(SyncApplierRegistry::class);
        $registry->register('ConcurrentProbe', new ProbeApplier);
        $registry->register('ConcurrentOrdered', new OrderedProbeApplier);

        try {
            return app(InboxProcessor::class)->receive(InboundEvent::fromEnvelope($envelope))->result;
        } catch (\Throwable $e) { // SQL messages embed binary ids: keep the JSON channel valid
            return 'ERROR '.class_basename($e).': '.substr(preg_replace('/[^\x20-\x7e]/', '?', $e->getMessage()), 0, 300);
        }
    }
}
