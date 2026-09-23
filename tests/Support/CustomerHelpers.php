<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Shared helpers for Customer/public API tests (in-process, real MySQL). */
trait CustomerHelpers
{
    public const PW = 'Correct-Horse-9!';

    protected function lastMailCode(): string
    {
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->all();
        $body = (string) end($messages)->getOriginalMessage()->getTextBody();
        $this->assertMatchesRegularExpression('/code is: (\d{6})/', $body);
        preg_match('/code is: (\d{6})/', $body, $m);

        return $m[1];
    }

    protected function lastMailLinkToken(string $param = 'token'): string
    {
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->all();
        preg_match('/[?&]'.$param.'=([A-Za-z0-9_-]+)/', (string) end($messages)->getOriginalMessage()->getTextBody(), $m);

        return $m[1] ?? '';
    }

    /** Register + verify a customer; returns the AuthResult. @return array<string, mixed> */
    protected function newCustomer(?string $email = null, ?string $phone = null): array
    {
        $email ??= 'guest'.bin2hex(random_bytes(4)).'@example.test';
        $this->postJson('/api/v1/customer/auth/register', ['name' => 'Ada Guest', 'email' => $email, 'phone' => $phone ?? '+23480'.random_int(10000000, 99999999), 'password' => self::PW])->assertStatus(201);
        $r = $this->postJson('/api/v1/customer/auth/verify', ['email' => $email, 'code' => $this->lastMailCode()])->assertOk();

        return $r->json() + ['email' => $email];
    }

    /** @return array<string, string> */
    protected function bearer(string $token, ?string $idem = 'auto'): array
    {
        return array_filter(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Idempotency-Key' => $idem === 'auto' ? 'k-'.bin2hex(random_bytes(10)) : $idem]);
    }

    protected function serviceToken(): string
    {
        return 'r7s_dev_booking_web';
    }

    protected function resourceByName(string $name): object
    {
        return DB::table('bookable_resource')->where('name', $name)->first();
    }
}
