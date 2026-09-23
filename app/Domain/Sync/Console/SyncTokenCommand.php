<?php

namespace App\Domain\Sync\Console;

use App\Domain\Sync\Services\NodeCredentials;
use Illuminate\Console\Command;

class SyncTokenCommand extends Command
{
    protected $signature = 'r007:sync:token {peer=local : the node that will PRESENT this token (local|cloud)} {--site= : bind the credential to one site UUID}';

    protected $description = 'Generate a node credential: the token goes to the presenting node (PEER_NODE_TOKEN), the hash to the receiving node (NODE_TOKEN_HASHES)';

    public function handle(): int
    {
        $peer = strtolower((string) $this->argument('peer'));
        if (! in_array($peer, ['local', 'cloud'], true)) {
            $this->error('peer must be local or cloud');

            return self::INVALID;
        }
        $t = NodeCredentials::generate();
        $entry = $peer.($this->option('site') ? '@'.strtolower($this->option('site')) : '').':'.$t['hash'];
        $this->warn('Shown once. Put the token in the secret store of the '.$peer.' node; never commit it.');
        $this->line('PEER_NODE_TOKEN (on the '.$peer.' node): '.$t['token']);
        $this->line('NODE_TOKEN_HASHES entry (on the receiving node; append with a comma to rotate): '.$entry);

        return self::SUCCESS;
    }
}
