<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Mail\SubscribeConfirmMail;
use App\Domain\Cms\Support\CmsAudit;
use App\Domain\Cms\Support\Rows;
use App\Support\Http\ApiProblem;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Newsletter subscribers: double opt-in (hashed token), signed one-click unsubscribe, idempotent and non-enumerating public subscribe.
 * Campaigns are out of scope; this module only stores consented addresses and lets admins list/export/erase them.
 */
class SubscriberService
{
    public const CHECK_EMAIL = ['status' => 'CHECK_EMAIL'];

    /** @param array<string, mixed> $d validated: email, name?, source, consent, consentText?, website? @return array{status: string} */
    public function subscribe(array $d, string $clientIp): array
    {
        $email = mb_strtolower(trim($d['email']));
        if (! blank($d['website'] ?? null)) {
            return self::CHECK_EMAIL; // honeypot: a person never fills it
        }
        $domain = substr(strrchr($email, '@') ?: '@', 1);
        if (in_array($domain, (array) config('cms.subscribers.disposable_domains'), true)) {
            Log::info('cms.subscribe.disposable_ignored');

            return self::CHECK_EMAIL;
        }
        try {
            $mail = DB::transaction(fn () => $this->upsert($email, $d, $clientIp));
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'uq_cms_subscriber_email') && $e->getCode() !== '23000') {
                throw $e;
            }
            $mail = null; // a concurrent identical request won the insert; it sends the mail
        }
        if ($mail !== null) {
            try {
                Mail::to($email)->queue($mail);
            } catch (\Throwable $e) {
                Log::warning('cms.subscribe.mail_failed', ['error' => $e->getMessage()]); // the response must not differ (no enumeration); the resend window lets the visitor retry
            }
        }

        return self::CHECK_EMAIL;
    }

    private function upsert(string $email, array $d, string $ip): ?SubscribeConfirmMail
    {
        $now = CarbonImmutable::now('UTC');
        $row = DB::table('cms_subscriber')->where('email', $email)->lockForUpdate()->first();
        $consent = mb_substr((string) ($d['consentText'] ?? config('cms.subscribers.default_consent_text')), 0, 500);
        if ($row !== null && $row->status === 'CONFIRMED') {
            return null;
        }
        if ($row !== null && $row->status === 'PENDING' && $row->confirm_sent_at !== null
            && Rows::carbon($row->confirm_sent_at)->gt($now->subMinutes((int) config('cms.subscribers.resend_after_minutes')))) {
            return null;
        }
        [$token, $hash] = $this->newToken();
        $fields = [
            'confirm_token_hash' => $hash, 'confirm_expires_at' => Rows::db($now->addHours((int) config('cms.subscribers.confirm_ttl_hours'))), 'confirm_sent_at' => Rows::db($now),
            'updated_at' => Rows::db($now),
        ];
        if ($row === null) {
            $id = Ids::uuid7();
            DB::table('cms_subscriber')->insert($fields + [
                'id' => Ids::toBinary($id), 'email' => $email, 'name' => filled($d['name'] ?? null) ? mb_substr(trim($d['name']), 0, 120) : null, 'source' => $d['source'] ?? 'footer', 'status' => 'PENDING',
                'consent_text' => $consent, 'consented_at' => Rows::db($now), 'ip_hash' => $this->ipHash($ip), 'created_at' => Rows::db($now),
            ]);
        } else {
            $id = Rows::id($row->id);
            DB::table('cms_subscriber')->where('id', $row->id)->update($fields + [
                'status' => 'PENDING', 'consent_text' => $consent, 'consented_at' => Rows::db($now), 'ip_hash' => $this->ipHash($ip), 'unsubscribed_at' => null,
                'name' => filled($d['name'] ?? null) ? mb_substr(trim($d['name']), 0, 120) : $row->name, 'row_version' => $row->row_version + 1,
            ]);
        }

        return new SubscribeConfirmMail($this->webUrl('/newsletter/confirm?token='.$token), $this->webUrl('/newsletter/unsubscribe?token='.$this->unsubscribeToken($id)), $d['name'] ?? null);
    }

    /** @return array{valid: true, status: string} */
    public function preview(string $token): array
    {
        $row = $this->byToken($token);

        return ['valid' => true, 'status' => $row->status];
    }

    /** @return array{status: string} */
    public function confirm(string $token): array
    {
        return DB::transaction(function () use ($token) {
            $row = $this->byToken($token, true);
            if ($row->status === 'CONFIRMED') {
                return ['status' => 'CONFIRMED'];
            }
            DB::table('cms_subscriber')->where('id', $row->id)->update(['status' => 'CONFIRMED', 'confirmed_at' => Rows::now(), 'updated_at' => Rows::now(), 'row_version' => $row->row_version + 1]);

            return ['status' => 'CONFIRMED'];
        });
    }

    /** @return array{status: string} */
    public function unsubscribe(string $token): array
    {
        $id = $this->verifyUnsubscribeToken($token) ?? throw ApiProblem::notFound('invalid_token', 'This link is not valid.');

        return DB::transaction(function () use ($id) {
            $row = DB::table('cms_subscriber')->where('id', Ids::toBinary($id))->lockForUpdate()->first() ?? throw ApiProblem::notFound('invalid_token', 'This link is not valid.');
            if ($row->status !== 'UNSUBSCRIBED') {
                DB::table('cms_subscriber')->where('id', $row->id)->update(['status' => 'UNSUBSCRIBED', 'unsubscribed_at' => Rows::now(), 'confirm_token_hash' => null, 'updated_at' => Rows::now(), 'row_version' => $row->row_version + 1]);
            }

            return ['status' => 'UNSUBSCRIBED'];
        });
    }

    /** Admin unsubscribe. @return array<string, mixed> */
    public function adminUnsubscribe(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $row = $this->find($id, true);
            if ($row->status !== 'UNSUBSCRIBED') {
                DB::table('cms_subscriber')->where('id', $row->id)->update(['status' => 'UNSUBSCRIBED', 'unsubscribed_at' => Rows::now(), 'confirm_token_hash' => null, 'updated_at' => Rows::now(), 'row_version' => $row->row_version + 1]);
                CmsAudit::record('cms.subscriber.unsubscribe', 'CmsSubscriber', $id, ['status' => $row->status], ['status' => 'UNSUBSCRIBED', 'emailHash' => hash('sha256', $row->email)]);
            }

            return $this->present($this->find($id));
        });
    }

    public function erase(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = $this->find($id, true);
            DB::table('cms_subscriber')->where('id', $row->id)->delete();
            CmsAudit::record('cms.subscriber.erase', 'CmsSubscriber', $id, ['status' => $row->status, 'source' => $row->source, 'emailHash' => hash('sha256', $row->email)], null);
        });
    }

    public function find(string $id, bool $lock = false): object
    {
        $q = Ids::isUuid($id) ? DB::table('cms_subscriber')->where('id', Ids::toBinary($id)) : null;
        if ($q !== null && $lock) {
            $q->lockForUpdate();
        }

        return $q?->first() ?? throw ApiProblem::notFound('not_found', 'Subscriber not found.');
    }

    /** @return array<string, mixed> */
    public function present(object $r): array
    {
        return [
            'id' => Rows::id($r->id), 'email' => $r->email, 'name' => $r->name, 'source' => $r->source, 'status' => $r->status, 'consentText' => $r->consent_text,
            'consentedAt' => Rows::iso($r->consented_at), 'confirmedAt' => Rows::iso($r->confirmed_at), 'unsubscribedAt' => Rows::iso($r->unsubscribed_at), 'createdAt' => Rows::iso($r->created_at),
        ];
    }

    public function unsubscribeToken(string $id): string
    {
        return rtrim(strtr(base64_encode(Ids::toBinary($id)), '+/', '-_'), '=').'.'.substr(hash_hmac('sha256', 'cms-unsub:'.Ids::normalize($id), $this->key()), 0, 32);
    }

    public function verifyUnsubscribeToken(string $token): ?string
    {
        [$b, $sig] = array_pad(explode('.', $token, 2), 2, '');
        $bin = base64_decode(strtr($b, '-_', '+/'), true);
        if ($bin === false || strlen($bin) !== 16 || $sig === '' || rtrim(strtr(base64_encode($bin), '+/', '-_'), '=') !== $b) { // canonical encoding only (no padding-bit aliases)
            return null;
        }
        $id = Ids::fromBinary($bin);

        return hash_equals(substr(hash_hmac('sha256', 'cms-unsub:'.$id, $this->key()), 0, 32), $sig) ? $id : null;
    }

    private function byToken(string $token, bool $lock = false): object
    {
        $q = DB::table('cms_subscriber')->where('confirm_token_hash', hash('sha256', $token));
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first() ?? throw ApiProblem::notFound('invalid_token', 'This link is not valid.');
        if ($row->status === 'PENDING' && ($row->confirm_expires_at === null || Rows::carbon($row->confirm_expires_at)->lt(CarbonImmutable::now('UTC')))) {
            throw new ApiProblem(410, 'token_expired', 'This confirmation link has expired. Subscribe again to get a new one.', 'Gone');
        }

        return $row;
    }

    /** @return array{0: string, 1: string} plain token, sha256 */
    private function newToken(): array
    {
        $t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$t, hash('sha256', $t)];
    }

    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->key());
    }

    private function webUrl(string $path): string
    {
        return rtrim((string) config('cms.web_url'), '/').$path;
    }

    private function key(): string
    {
        return (string) config('app.key');
    }
}
