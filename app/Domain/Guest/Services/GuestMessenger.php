<?php

namespace App\Domain\Guest\Services;

use App\Domain\Guest\Contracts\SmsSender;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Outbox for guest confirmation / ticket messages (`guest_message`). Rows are queued inside the payment transaction; `deliverDue()`
 * (command r007:guest-messages:send, every minute) sends them: email through Laravel Mail (driver `log` until SMTP is configured), SMS
 * through the SmsSender port (stub). Every delivery mints a FRESH order access token for the link, so the link works without a lookup.
 */
class GuestMessenger
{
    private const FMT = 'Y-m-d H:i:s.u';

    public function __construct(private readonly GuestAccess $access, private readonly SmsSender $sms) {}

    /** Payment captured: queue the confirmation once per channel (idempotent). */
    public function queueConfirmation(string $guestOrderBin): void
    {
        foreach (['EMAIL', 'SMS'] as $channel) {
            $exists = DB::table('guest_message')->where('guest_order_id', $guestOrderBin)->where('channel', $channel)->where('template', 'CONFIRMATION')->exists();
            $exists || $this->insert($guestOrderBin, $channel, 'CONFIRMATION');
        }
    }

    public function queueResend(string $guestOrderBin, string $channel): void
    {
        $this->insert($guestOrderBin, $channel, 'RESEND');
    }

    private function insert(string $guestOrderBin, string $channel, string $template): void
    {
        DB::table('guest_message')->insert(['id' => Ids::toBinary(Ids::uuid7()), 'guest_order_id' => $guestOrderBin, 'channel' => $channel, 'template' => $template]);
    }

    /** @return array{sent: int, failed: int} */
    public function deliverDue(int $limit = 50): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        $due = DB::table('guest_message')->where('status', 'QUEUED')->where('next_attempt_at', '<=', now('UTC')->format(self::FMT))->orderBy('created_at')->limit($limit)->get();
        foreach ($due as $m) {
            $attempts = (int) $m->attempts + 1;
            // claim: bump attempts and push next_attempt_at out so a parallel runner skips it
            $won = DB::table('guest_message')->where('id', $m->id)->where('status', 'QUEUED')->where('attempts', $m->attempts)
                ->update(['attempts' => $attempts, 'next_attempt_at' => now('UTC')->addMinutes(2 ** min($attempts, 6))->format(self::FMT)]);
            if ($won === 0) {
                continue;
            }
            $o = DB::table('guest_order')->where('id', $m->guest_order_id)->first();
            $to = $o === null ? null : ($m->channel === 'EMAIL' ? $o->contact_email : $o->contact_phone);
            if ($o === null || $o->erased_at !== null || $to === null) {
                DB::table('guest_message')->where('id', $m->id)->update(['status' => 'CANCELLED']);

                continue;
            }
            try {
                DB::transaction(function () use ($m, $o, $to): void {
                    $tok = $this->access->mint(Ids::fromBinary($o->id));
                    $link = rtrim((string) config('guest.web_url'), '/').'/order/'.$o->reference.'?token='.$tok['token'];
                    $m->channel === 'EMAIL' ? $this->email($o, $to, $link) : $this->sms->send($to, $this->smsText($o, $link));
                });
                DB::table('guest_message')->where('id', $m->id)->update(['status' => 'SENT', 'sent_at' => now('UTC')->format(self::FMT), 'last_error' => null]);
                $out['sent']++;
            } catch (\Throwable $e) {
                $final = $attempts >= (int) config('guest.message_max_attempts');
                DB::table('guest_message')->where('id', $m->id)->update(['status' => $final ? 'FAILED' : 'QUEUED', 'last_error' => mb_substr(get_class($e), 0, 200)]);
                Log::warning('guest.message.failed', ['message' => Ids::fromBinary($m->id), 'channel' => $m->channel, 'attempt' => $attempts, 'error' => get_class($e)]);
                $out['failed']++;
            }
        }

        return $out;
    }

    private function email(object $o, string $to, string $link): void
    {
        $site = (string) (config('customer.site.name') ?: '007 Resort & Spa');
        $what = ['BOOKING' => 'booking', 'TICKETS' => 'tickets', 'MEMBERSHIP' => 'membership'][$o->kind];
        $body = "Hello {$o->contact_name},\n\nThank you. Your {$what} at {$site} is confirmed.\nReference: {$o->reference}\n\nView your {$what} and your QR code(s): {$link}\n\nKeep this email: the link above is your way back to your order. You do not need an account.\n";
        Mail::raw($body, fn ($m) => $m->to($to)->subject("Your {$what} at {$site} ({$o->reference})"));
    }

    private function smsText(object $o, string $link): string
    {
        return "007 Resort: your {$o->kind} {$o->reference} is confirmed. Tickets: {$link}";
    }
}
