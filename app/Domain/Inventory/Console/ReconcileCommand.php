<?php

namespace App\Domain\Inventory\Console;

use App\Domain\Inventory\Services\ReconciliationService;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'r007:inventory:reconcile {--fix : rebuild drifted stock_balance rows from the ledger} {--json : machine-readable output}';

    protected $description = 'Compare stock_balance to SUM(stock_movement) per (item, location); exit 1 on drift (nightly, see scheduler)';

    public function handle(ReconciliationService $svc): int
    {
        $pairs = $svc->checkedPairs();
        $drift = $svc->drift();

        if ($drift !== []) {
            Audit::securityEvent('inventory.reconcile_drift', 'CRITICAL', null, null, ['pairs' => count($drift), 'sample' => array_slice($drift, 0, 20)]);
        }
        $fixed = ($this->option('fix') && $drift !== []) ? $svc->repair($drift) : 0;

        if ($this->option('json')) {
            $this->line(json_encode(['checkedPairs' => $pairs, 'drift' => $drift, 'repaired' => $fixed], JSON_UNESCAPED_SLASHES));
        } elseif ($drift === []) {
            $this->info("Inventory reconciled: {$pairs} (item, location) pairs, no drift.");
        } else {
            $this->error(count($drift).' drifted pair(s) of '.$pairs.($fixed ? "; repaired {$fixed}" : ''));
            $this->table(['item', 'location', 'balance', 'ledger'], array_map(fn ($d) => [$d['itemId'], $d['locationId'], $d['balance'], $d['ledger']], $drift));
        }

        return $drift === [] ? self::SUCCESS : self::FAILURE;
    }
}
