<?php

namespace App\Domain\Customer\Console;

use App\Domain\Customer\Services\ServiceTokenService;
use Illuminate\Console\Command;

class ServiceTokenCommand extends Command
{
    protected $signature = 'r007:service-token {action : create|rotate|revoke|list} {--name=booking-web} {--scope=public.read : comma-set: public.read,public.checkout,customer.social} {--id=} {--now : rotate: expire the old token immediately}';

    protected $description = 'Manage the website service token (scopes public.read, public.checkout for guest checkout). The plaintext is printed once.';

    public function handle(ServiceTokenService $svc): int
    {
        switch ($this->argument('action')) {
            case 'create':
                $r = $svc->create((string) $this->option('name'), scope: (string) $this->option('scope'));
                $this->line("id: {$r['id']}");
                $this->line("token (shown once; set R007_API_SERVICE_TOKEN on the website): {$r['token']}");
                break;
            case 'rotate':
                $r = $svc->rotate((string) $this->option('id'), (bool) $this->option('now'));
                $this->line("new id: {$r['id']}  old token valid until: {$r['oldTokenExpiresAt']}");
                $this->line("token (shown once): {$r['token']}");
                break;
            case 'revoke':
                $svc->revoke((string) $this->option('id'));
                $this->info('revoked');
                break;
            case 'list':
                foreach ($svc->list() as $t) {
                    $this->line(json_encode($t));
                }
                break;
            default:
                $this->error('unknown action');

                return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
