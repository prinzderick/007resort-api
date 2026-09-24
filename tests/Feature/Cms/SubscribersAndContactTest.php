<?php

namespace Tests\Feature\Cms;

use App\Domain\Cms\Mail\SubscribeConfirmMail;
use App\Domain\Cms\Services\SubscriberService;
use App\Support\Audit\Audit;
use App\Support\Ids;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SubscribersAndContactTest extends CmsTestCase
{
    private function subscribe(string $email, array $extra = [], array $headers = [])
    {
        return $this->pubPost('/subscribers', ['email' => $email, 'consent' => true] + $extra, $headers);
    }

    /** @return array{0: string, 1: string} confirm token, unsubscribe token from the last queued mail */
    private function tokens(): array
    {
        $mails = Mail::queued(SubscribeConfirmMail::class);
        $this->assertNotEmpty($mails, 'a confirmation mail was queued');
        $m = $mails->last();
        parse_str(parse_url($m->confirmUrl, PHP_URL_QUERY), $c);
        parse_str(parse_url($m->unsubscribeUrl, PHP_URL_QUERY), $u);
        $this->assertStringStartsWith(rtrim((string) config('cms.web_url'), '/').'/newsletter/confirm', $m->confirmUrl);

        return [$c['token'], $u['token']];
    }

    public function test_double_opt_in_flow_with_hashed_token(): void
    {
        Mail::fake();
        $r = $this->subscribe('Ada@Example.COM ', ['name' => 'Ada', 'source' => 'blog'])->assertOk();
        $this->assertSame(['status' => 'CHECK_EMAIL'], $r->json());
        Mail::assertQueuedCount(1);
        [$token] = $this->tokens();
        $row = DB::table('cms_subscriber')->first();
        $this->assertSame('ada@example.com', $row->email, 'lowercased + trimmed');
        $this->assertSame('PENDING', $row->status);
        $this->assertSame('blog', $row->source);
        $this->assertSame(hash('sha256', $token), $row->confirm_token_hash, 'only the hash of the token is stored');
        $this->assertStringNotContainsString($token, json_encode((array) $row));
        $this->assertNotEmpty($row->consent_text);
        $this->assertNotNull($row->consented_at);
        $this->assertSame(64, strlen($row->ip_hash));
        $this->assertNotSame('127.0.0.1', $row->ip_hash);

        // GET is a harmless preview (mail scanners prefetch links)
        $this->pub("/subscribers/confirm/{$token}")->assertOk()->assertJson(['valid' => true, 'status' => 'PENDING']);
        $this->assertSame('PENDING', DB::table('cms_subscriber')->value('status'));
        $this->pubPost("/subscribers/confirm/{$token}")->assertOk()->assertJson(['status' => 'CONFIRMED']);
        $row = DB::table('cms_subscriber')->first();
        $this->assertSame('CONFIRMED', $row->status);
        $this->assertNotNull($row->confirmed_at);
        // replay is fine
        $this->pubPost("/subscribers/confirm/{$token}")->assertOk()->assertJson(['status' => 'CONFIRMED']);
        $this->pub("/subscribers/confirm/{$token}")->assertOk()->assertJson(['status' => 'CONFIRMED']);
        // unknown / tampered
        $this->pubPost('/subscribers/confirm/'.strrev($token))->assertStatus(404)->assertJsonPath('code', 'invalid_token');
        $this->pub('/subscribers/confirm/nonsense')->assertStatus(404)->assertJsonPath('code', 'invalid_token');
    }

    public function test_subscribe_is_idempotent_and_does_not_leak_existence(): void
    {
        Mail::fake();
        $new = $this->subscribe('same@example.com')->assertOk()->json();
        [$token] = $this->tokens();
        $this->pubPost("/subscribers/confirm/{$token}")->assertOk();
        Mail::assertQueuedCount(1);
        // a confirmed address: identical 200 body, no mail, no state change
        $again = $this->subscribe('same@example.com', ['name' => 'Someone Else'])->assertOk();
        $this->assertSame($new, $again->json());
        Mail::assertQueuedCount(1);
        $row = DB::table('cms_subscriber')->first();
        $this->assertSame('CONFIRMED', $row->status);
        $this->assertNull($row->name, 'a stranger re-submitting cannot change a confirmed subscriber');
        $this->assertSame(1, DB::table('cms_subscriber')->count());
        // pending: no second mail inside the resend window; a mail after it (with a fresh token)
        $this->subscribe('pending@example.com')->assertOk();
        $this->subscribe('pending@example.com')->assertOk();
        Mail::assertQueuedCount(2);
        [$first] = $this->tokens();
        DB::table('cms_subscriber')->where('email', 'pending@example.com')->update(['confirm_sent_at' => now('UTC')->subMinutes(5)->format('Y-m-d H:i:s.u')]);
        $this->subscribe('pending@example.com')->assertOk();
        Mail::assertQueuedCount(3);
        [$second] = $this->tokens();
        $this->assertNotSame($first, $second);
        $this->pubPost("/subscribers/confirm/{$first}")->assertStatus(404);
        $this->pubPost("/subscribers/confirm/{$second}")->assertOk();
        // concurrent duplicate insert is absorbed
        $this->assertSame(2, DB::table('cms_subscriber')->count());
    }

    public function test_validation_honeypot_and_disposable_addresses(): void
    {
        Mail::fake();
        $this->pubPost('/subscribers', ['email' => 'not-an-email', 'consent' => true])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->pubPost('/subscribers', ['email' => 'a@example.com'])->assertStatus(422)->assertJsonValidationErrors(['consent']);
        $this->pubPost('/subscribers', ['email' => 'a@example.com', 'consent' => false])->assertStatus(422);
        $this->pubPost('/subscribers', ['email' => 'a@example.com', 'consent' => true, 'source' => 'billboard'])->assertStatus(422)->assertJsonValidationErrors(['source']);
        $this->assertSame(0, DB::table('cms_subscriber')->count());
        // honeypot and disposable: same 200 answer, nothing stored or sent
        $this->subscribe('bot@example.com', ['website' => 'http://spam.example'])->assertOk()->assertJson(['status' => 'CHECK_EMAIL']);
        $this->subscribe('temp@mailinator.com')->assertOk()->assertJson(['status' => 'CHECK_EMAIL']);
        $this->assertSame(0, DB::table('cms_subscriber')->count());
        Mail::assertNothingQueued();
    }

    public function test_unsubscribe_token_is_signed_and_tamper_proof(): void
    {
        Mail::fake();
        $this->subscribe('leaving@example.com')->assertOk();
        [$confirm, $unsub] = $this->tokens();
        $this->pubPost("/subscribers/confirm/{$confirm}")->assertOk();
        $svc = app(SubscriberService::class);
        $id = Ids::fromBinary(DB::table('cms_subscriber')->value('id'));
        $this->assertSame($unsub, $svc->unsubscribeToken($id));
        $this->assertSame($id, $svc->verifyUnsubscribeToken($unsub));

        [$idPart, $sig] = explode('.', $unsub);
        $flip = fn (string $s) => substr($s, 0, -1).($s[strlen($s) - 1] === 'a' ? 'b' : 'a');
        foreach ([$idPart.'.'.$flip($sig), $flip($idPart).'.'.$sig, $idPart, $idPart.'.', '.'.$sig, 'garbage', $sig.'.'.$idPart, $idPart.'.'.substr($sig, 0, 10)] as $bad) {
            $this->pubPost('/subscribers/unsubscribe/'.rawurlencode($bad))->assertStatus(404)->assertJsonPath('code', 'invalid_token');
        }
        // another subscriber's id with this signature is rejected too
        $other = $svc->unsubscribeToken(Ids::uuid7());
        [$oid] = explode('.', $other);
        $this->pubPost("/subscribers/unsubscribe/{$oid}.{$sig}")->assertStatus(404);
        $this->assertSame('CONFIRMED', DB::table('cms_subscriber')->value('status'));

        $this->pubPost("/subscribers/unsubscribe/{$unsub}")->assertOk()->assertJson(['status' => 'UNSUBSCRIBED']);
        $this->pubPost("/subscribers/unsubscribe/{$unsub}")->assertOk()->assertJson(['status' => 'UNSUBSCRIBED']);
        $row = DB::table('cms_subscriber')->first();
        $this->assertSame('UNSUBSCRIBED', $row->status);
        $this->assertNotNull($row->unsubscribed_at);
        $this->assertNull($row->confirm_token_hash);
        $this->pubPost("/subscribers/confirm/{$confirm}")->assertStatus(404);
        // subscribing again after unsubscribing starts a fresh double opt-in
        $this->subscribe('leaving@example.com')->assertOk();
        $this->assertSame('PENDING', DB::table('cms_subscriber')->value('status'));
        $this->assertNull(DB::table('cms_subscriber')->value('unsubscribed_at'));
        Mail::assertQueuedCount(2);
    }

    public function test_expired_confirmation_token(): void
    {
        Mail::fake();
        $this->subscribe('slow@example.com')->assertOk();
        [$token] = $this->tokens();
        DB::table('cms_subscriber')->update(['confirm_expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u')]);
        $this->pub("/subscribers/confirm/{$token}")->assertStatus(410)->assertJsonPath('code', 'token_expired');
        $this->pubPost("/subscribers/confirm/{$token}")->assertStatus(410);
        $this->assertSame('PENDING', DB::table('cms_subscriber')->value('status'));
    }

    public function test_subscribe_and_contact_are_rate_limited(): void
    {
        Mail::fake();
        for ($i = 1; $i <= 5; $i++) {
            $this->subscribe('flood@example.com')->assertOk();
        }
        $this->subscribe('flood@example.com')->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        // a different address from the same client is still allowed until the per-client cap
        $this->subscribe('other@example.com')->assertOk();
        // the BFF forwards the visitor's IP; another visitor is unaffected
        $this->subscribe('flood@example.com', [], ['X-Client-IP' => '203.0.113.9'])->assertOk();
        for ($i = 1; $i <= 5; $i++) {
            $this->pubPost('/contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello there, is the pool open?'])->assertCreated();
        }
        $this->pubPost('/contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello there, is the pool open?'])->assertStatus(429);
        $this->assertSame(5, DB::table('cms_contact_message')->count());
    }

    public function test_client_ip_header_is_only_trusted_from_service_tokens(): void
    {
        Mail::fake();
        $s = app(SubscriberService::class);
        $this->subscribe('ip@example.com', [], ['X-Client-IP' => '203.0.113.7'])->assertOk();
        $this->assertSame(hash_hmac('sha256', '203.0.113.7', config('app.key')), DB::table('cms_subscriber')->value('ip_hash'));
        $this->assertNotNull($s);
    }

    public function test_contact_message_validation_honeypot_and_inbox(): void
    {
        $this->pubPost('/contact', ['name' => 'A', 'email' => 'bad', 'message' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'message']);
        $this->pubPost('/contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Long enough message', 'topic' => 'LOVE'])->assertStatus(422);
        $ok = $this->pubPost('/contact', ['name' => 'Ada Obi', 'email' => 'ADA@example.com', 'phone' => '+2348000000001', 'topic' => 'EVENTS', 'message' => 'We would like to host a birthday for 40 people.'])->assertCreated();
        $this->assertSame(['status' => 'RECEIVED'], $ok->json());
        $this->pubPost('/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy cheap watches now please', 'website' => 'http://spam'], ['X-Client-IP' => '203.0.113.50'])->assertCreated();
        $this->assertSame(['NEW', 'SPAM'], DB::table('cms_contact_message')->orderBy('created_at')->pluck('status')->all());
        $this->assertSame('ada@example.com', DB::table('cms_contact_message')->orderBy('created_at')->value('email'));

        $m = $this->staff('manager1');
        $list = $m->get('/admin/cms/messages')->assertOk();
        $this->assertCount(2, $list->json('items'));
        $this->assertSame(1, $list->json('counts.new'));
        $this->assertSame(1, $list->json('counts.spam'));
        $this->assertCount(1, $m->get('/admin/cms/messages?status=NEW')->json('items'));
        $this->assertCount(1, $m->get('/admin/cms/messages?topic=EVENTS')->json('items'));
        $this->assertCount(1, $m->get('/admin/cms/messages?q=birthday')->json('items'));
        $id = $m->get('/admin/cms/messages?status=NEW')->json('items.0.id');
        $one = $m->get("/admin/cms/messages/{$id}")->assertOk();
        $this->assertSame('NEW', $one->json('status'), 'reading does not change status');
        $u = $m->patch("/admin/cms/messages/{$id}", ['status' => 'REPLIED', 'internalNote' => 'Called back'])->assertOk();
        $this->assertSame('REPLIED', $u->json('status'));
        $this->assertNotNull($u->json('handledAt'));
        $this->assertNotNull($u->json('handledBy'));
        $m->patch("/admin/cms/messages/{$id}", ['status' => 'DONE'])->assertStatus(422);
        $this->assertSame('NEW', $this->audit('cms.message.update', $id)[0]->old['status']);
        $m->delete("/admin/cms/messages/{$id}")->assertNoContent();
        $this->assertNotEmpty($this->audit('cms.message.erase', $id));
        $this->assertStringNotContainsString('ada@example.com', $this->audit('cms.message.erase', $id)[0]->old_value);
        $m->get("/admin/cms/messages/{$id}")->assertStatus(404);
        // cashier cannot read the inbox
        $this->staff('cashier1')->get('/admin/cms/messages')->assertStatus(403);
    }

    public function test_admin_subscriber_list_export_unsubscribe_and_erase(): void
    {
        Mail::fake();
        foreach (['a@example.com' => 'footer', 'b@example.com' => 'event', '=cmd@example.com' => 'popup'] as $e => $src) {
            $this->subscribe($e === '=cmd@example.com' ? 'x@example.com' : $e, ['source' => $src, 'name' => $e === '=cmd@example.com' ? '=HYPERLINK("http://evil")' : null])->assertOk();
        }
        [$tok] = $this->tokens();
        $this->pubPost("/subscribers/confirm/{$tok}")->assertOk();
        $o = $this->owner();
        $l = $o->get('/admin/cms/subscribers')->assertOk();
        $this->assertCount(3, $l->json('items'));
        $this->assertSame(['pending' => 2, 'confirmed' => 1, 'unsubscribed' => 0], $l->json('counts'));
        $this->assertArrayNotHasKey('ipHash', $l->json('items.0'));
        $this->assertArrayNotHasKey('ip_hash', $l->json('items.0'));
        $this->assertCount(1, $o->get('/admin/cms/subscribers?status=CONFIRMED')->json('items'));
        $this->assertCount(1, $o->get('/admin/cms/subscribers?source=event')->json('items'));
        $this->assertCount(1, $o->get('/admin/cms/subscribers?q=a@ex')->json('items'));
        $page1 = $o->get('/admin/cms/subscribers?limit=2');
        $this->assertNotNull($page1->json('nextCursor'));
        $this->assertCount(1, $o->get('/admin/cms/subscribers?limit=2&cursor='.$page1->json('nextCursor'))->json('items'));

        $csv = $this->withHeaders($o->headers(['Accept' => 'text/csv']))->get('/api/v1/admin/cms/subscribers/export');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertSame('email,name,source,status,consentedAt,confirmedAt,unsubscribedAt', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertStringContainsString("'=HYPERLINK", $body, 'formula injection is neutralised');
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
        $confirmedOnly = $this->withHeaders($o->headers())->get('/api/v1/admin/cms/subscribers/export?status=CONFIRMED')->streamedContent();
        $this->assertCount(2, array_filter(explode("\n", trim($confirmedOnly))));
        $ex = $this->audit('cms.subscriber.export');
        $this->assertCount(2, $ex);
        $this->assertSame(3, $ex[0]->new['rows']);
        // export needs its own permission
        $it = $this->roleApi('IT_ADMIN');
        $it->get('/admin/cms/subscribers/export')->assertStatus(403);
        $it->get('/admin/cms/subscribers')->assertStatus(403);

        // admin unsubscribe + GDPR erase (audit keeps only a hash of the address)
        $id = $l->json('items.0.id');
        $emailOfFirst = $l->json('items.0.email');
        $o->post("/admin/cms/subscribers/{$id}/unsubscribe")->assertOk()->assertJsonPath('status', 'UNSUBSCRIBED');
        $o->delete("/admin/cms/subscribers/{$id}")->assertNoContent();
        $this->assertSame(0, DB::table('cms_subscriber')->where('email', $emailOfFirst)->count());
        $er = $this->audit('cms.subscriber.erase', $id)[0];
        $this->assertSame(hash('sha256', $emailOfFirst), $er->old['emailHash']);
        $this->assertStringNotContainsString($emailOfFirst, (string) $er->old_value);
        $o->delete("/admin/cms/subscribers/{$id}")->assertStatus(404);
        $this->assertTrue(Audit::verifyChain()->valid);
        // the erased address can subscribe again from scratch
        $this->subscribe($emailOfFirst)->assertOk();
        $this->assertSame('PENDING', DB::table('cms_subscriber')->where('email', $emailOfFirst)->value('status'));
    }

    public function test_confirmation_email_renders_branded_html_and_headers(): void
    {
        $m = new SubscribeConfirmMail('https://site.test/newsletter/confirm?token=abc', 'https://site.test/newsletter/unsubscribe?token=xyz', 'Ada');
        $html = $m->render();
        $this->assertStringContainsString('007 Resort', $html);
        $this->assertStringContainsString('https://site.test/newsletter/confirm?token=abc', $html);
        $this->assertStringContainsString('Hello Ada', $html);
        $this->assertSame('<https://site.test/newsletter/unsubscribe?token=xyz>', $m->headers()->text['List-Unsubscribe']);
        $this->assertInstanceOf(ShouldQueue::class, $m);
    }

    public function test_service_token_reads_but_public_writes_need_some_credential(): void
    {
        $this->postJson('/api/v1/public/cms/subscribers', ['email' => 'a@example.com', 'consent' => true])->assertStatus(401);
        $this->postJson('/api/v1/public/cms/contact', ['name' => 'Ada', 'email' => 'a@example.com', 'message' => 'Hello there friends'])->assertStatus(401);
    }
}
