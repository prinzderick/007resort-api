<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentTerminalAdapter;
use App\Domain\Payments\Provider\Terminal\ManualBankTerminalAdapter;
use App\Domain\Payments\Provider\Terminal\PaystackTerminalAdapter;
use App\Support\Http\ApiProblem;

/** Provider code -> {@see PaymentTerminalAdapter}. Register a real integration with `extend()` from its own service provider. */
final class TerminalAdapterRegistry
{
    /** @var array<string, PaymentTerminalAdapter> */
    private array $adapters = [];

    public function __construct()
    {
        $this->extend(new ManualBankTerminalAdapter);
        $this->extend(new PaystackTerminalAdapter);
    }

    public function extend(PaymentTerminalAdapter $adapter): void
    {
        $this->adapters[$adapter->code()] = $adapter;
    }

    public function for(string $provider): PaymentTerminalAdapter
    {
        return $this->adapters[$provider] ?? throw new ApiProblem(501, 'terminal_provider_unavailable', "No adapter is registered for terminal provider {$provider}.", 'Not implemented');
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->adapters);
    }
}
