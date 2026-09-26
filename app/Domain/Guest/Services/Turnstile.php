<?php

namespace App\Domain\Guest\Services;

use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\Http;

/** Optional Cloudflare Turnstile verification. Off unless GUEST_TURNSTILE_SECRET is set; fails closed with stable codes. */
class Turnstile
{
    public function enabled(): bool
    {
        return (string) config('guest.turnstile_secret') !== '';
    }

    public function assertHuman(?string $token, ?string $ip): void
    {
        if (! $this->enabled()) {
            return;
        }
        if ($token === null || trim($token) === '') {
            throw ApiProblem::unprocessable('captcha_required', 'Please complete the human check.');
        }
        try {
            $r = Http::asForm()->timeout(5)->post((string) config('guest.turnstile_url'), array_filter([
                'secret' => config('guest.turnstile_secret'), 'response' => $token, 'remoteip' => $ip,
            ]));
            $ok = $r->successful() && $r->json('success') === true;
        } catch (\Throwable) {
            $ok = false;
        }
        if (! $ok) {
            throw ApiProblem::unprocessable('captcha_failed', 'The human check failed. Please try again.');
        }
    }
}
