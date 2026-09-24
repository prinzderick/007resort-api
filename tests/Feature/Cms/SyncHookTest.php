<?php

namespace Tests\Feature\Cms;

use Illuminate\Support\Facades\DB;

class SyncHookTest extends CmsTestCase
{
    public function test_no_outbox_events_by_default(): void
    {
        $this->newMedia();
        $this->published('pages', ['title' => 'P', 'bodyMarkdown' => 'x']);
        $this->assertSame(0, DB::table('outbox_event')->whereIn('event_type', ['CmsContentPublished', 'CmsMediaUploaded'])->count());
    }

    public function test_outbox_events_when_enabled_are_written_in_the_same_transaction(): void
    {
        config()->set('cms.sync.emit', true);
        $m = $this->newMedia('Synced');
        $p = $this->published('pages', ['title' => 'Synced page', 'bodyMarkdown' => 'x']);
        $media = DB::table('outbox_event')->where('event_type', 'CmsMediaUploaded')->first();
        $this->assertNotNull($media);
        $payload = json_decode($media->payload, true);
        $this->assertSame($m['id'], $payload['id']);
        $this->assertSame(64, strlen($payload['sha256']));
        $this->assertStringStartsWith('cms/', $payload['path']);
        $events = DB::table('outbox_event')->where('event_type', 'CmsContentPublished')->orderBy('created_at')->get()->map(fn ($e) => json_decode($e->payload, true));
        $this->assertSame(['create', 'publish'], $events->pluck('action')->all());
        $this->assertSame($p['id'], $events->last()['id']);
        $this->assertSame('Synced page', $events->last()['snapshot']['title']);
        // a rolled-back business change leaves no outbox row
        $before = DB::table('outbox_event')->count();
        $this->owner()->post('/admin/cms/pages', ['title' => 'Bad', 'bodyMarkdown' => 'x', 'heroMediaId' => '0192a1b2-0000-7000-8000-000000000000'])->assertStatus(422);
        $this->assertSame($before, DB::table('outbox_event')->count());
    }
}
