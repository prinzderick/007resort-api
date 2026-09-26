<?php

namespace App\Domain\Guest\Services;

use App\Domain\Guest\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/** Stub: logs a masked number and the length only (never the message body: it carries an access link). */
class LogSmsSender implements SmsSender
{
    public function send(string $e164, string $text): void
    {
        Log::info('guest.sms.stub', ['to' => substr($e164, 0, 5).'****'.substr($e164, -2), 'chars' => strlen($text)]);
    }
}
