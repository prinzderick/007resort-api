<?php

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Services\SyncAdmin;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Illuminate\Console\Command;

class SyncRetryCommand extends Command
{
    protected $signature = 'r007:sync:retry {id : event id (UUID)} {--inbox : the id is an INBOX event (reprocess) instead of an outbox event}';

    protected $description = 'Retry one FAILED outbox event, or reprocess one stored inbox event (audited)';

    public function handle(SyncAdmin $admin): int
    {
        $id = (string) $this->argument('id');
        if (! Ids::isUuid($id)) {
            $this->error('id must be a UUID');

            return self::INVALID;
        }
        $id = Ids::normalize($id);

        try {
            if ($this->option('inbox')) {
                $o = $admin->reprocessInbox($id);
                $this->info("Inbox event {$id}: {$o->result}".($o->detail ? " ({$o->detail})" : ''));
            } else {
                $admin->retryOutbox($id);
                $this->info("Outbox event {$id} re-queued.");
            }
        } catch (ApiProblem $e) {
            $this->error($e->problemCode.': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
