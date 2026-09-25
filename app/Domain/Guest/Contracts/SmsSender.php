<?php

namespace App\Domain\Guest\Contracts;

/** SMS delivery port. The default `LogSmsSender` is a stub (no provider is configured yet); a real provider = one more class bound in GuestServiceProvider. */
interface SmsSender
{
    /** @throws \Throwable on failure (the message is retried with backoff) */
    public function send(string $e164, string $text): void;
}
