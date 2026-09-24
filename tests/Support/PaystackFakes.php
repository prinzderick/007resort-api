<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Paystack sandbox stand-in: no test ever talks to the real API. Secret is a throwaway test value. */
final class PaystackFakes
{
    public const SECRET = 'paystack-test-hmac-secret-not-a-real-key';

    public static function configure(): void
    {
        config([
            'payments.paystack.secret_key' => self::SECRET, 'payments.paystack.base_url' => 'https://api.paystack.co',
            'payments.paystack.webhook_allowed_ips' => [], 'payments.paystack.callback_url' => 'https://example.test/return',
        ]);
    }

    /** @param string $verifyStatus success|failed|abandoned  @param ?int $verifyKobo override the amount Paystack reports */
    public static function http(string $verifyStatus = 'success', ?int $verifyKobo = null, string $currency = 'NGN'): void
    {
        Http::swap(new Factory); // fresh factory: a later fake() must REPLACE earlier stubs, not queue behind them
        Http::fake([
            'api.paystack.co/transaction/initialize' => function (Request $r) {
                $ref = $r['reference'];

                return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/'.substr($ref, -8), 'access_code' => 'ac_'.substr($ref, -8), 'reference' => $ref,
                ]]);
            },
            'api.paystack.co/charge' => function (Request $r) {
                return Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => [
                    'reference' => $r['reference'], 'status' => 'pay_offline', 'account_number' => '9912345678', 'account_name' => '007 RESORT TEST',
                    'bank' => ['name' => 'Test Bank', 'slug' => 'test-bank'], 'account_expires_at' => $r['bank_transfer']['account_expires_at'] ?? null,
                ]]);
            },
            'api.paystack.co/transaction/verify/*' => function (Request $r) use ($verifyStatus, $verifyKobo, $currency) {
                $ref = rawurldecode(substr((string) strrchr($r->url(), '/'), 1));
                $amount = $verifyKobo ?? (int) (self::$lastInitKobo[$ref] ?? 0);

                return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => [
                    'id' => 4099260516, 'status' => $verifyStatus, 'reference' => $ref, 'amount' => $amount, 'currency' => $currency, 'gateway_response' => $verifyStatus === 'success' ? 'Successful' : 'Declined',
                ]]);
            },
        ]);
    }

    /** @var array<string, int> */
    public static array $lastInitKobo = [];

    public static function remember(string $reference, string $naira): void
    {
        self::$lastInitKobo[$reference] = (int) bcmul($naira, '100', 0);
    }

    public static function body(string $reference, int $txnId = 4099260516, string $event = 'charge.success', ?int $kobo = null): string
    {
        return json_encode(['event' => $event, 'data' => ['id' => $txnId, 'reference' => $reference, 'status' => 'success', 'amount' => $kobo ?? (self::$lastInitKobo[$reference] ?? 0), 'currency' => 'NGN',
            'customer' => ['email' => 'guest@example.test']]], JSON_UNESCAPED_SLASHES);
    }

    public static function sign(string $body, string $secret = self::SECRET): string
    {
        return hash_hmac('sha512', $body, $secret);
    }
}
