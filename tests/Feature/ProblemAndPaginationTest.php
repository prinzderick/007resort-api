<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\FacilityUnit;
use App\Support\Http\ApiProblem;
use App\Support\Http\CursorPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestData;
use Tests\TestCase;

class ProblemAndPaginationTest extends TestCase
{
    public function test_problem_json_shape_and_stable_codes(): void
    {
        Route::middleware('api')->prefix('api/v1/_p')->group(function () {
            Route::get('conflict', fn () => throw ApiProblem::conflict('slot_taken', 'Slot already booked.', ['slotId' => 'abc']));
            Route::get('boom', fn () => throw new \RuntimeException('secret internals'));
            Route::post('validate', fn (Request $r) => $r->validate(['qty' => 'required|integer|min:1']));
        });

        $this->getJson('/api/v1/_p/conflict')->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['type' => 'urn:r007:problem:slot_taken', 'title' => 'Conflict', 'status' => 409, 'code' => 'slot_taken', 'detail' => 'Slot already booked.', 'slotId' => 'abc', 'instance' => '/api/v1/_p/conflict']);

        $this->get('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated'); // no Accept header
        $this->getJson('/api/v1/nope')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/auth/staff/logout', [], ['X-Correlation-Id' => 'abc-123'])->assertStatus(401)->assertJsonPath('code', 'unauthenticated')->assertJsonPath('correlationId', 'abc-123')->assertHeader('X-Correlation-Id', 'abc-123')->assertHeader('WWW-Authenticate', 'Bearer');
        $this->getJson('/api/v1/auth/staff/login')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
        $this->postJson('/api/v1/_p/validate', ['qty' => 0])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.qty.0', 'The qty field must be at least 1.');

        config(['app.debug' => false]);
        $this->getJson('/api/v1/_p/boom')->assertStatus(500)->assertJsonPath('code', 'server_error')->assertJsonMissingPath('debug');
    }

    public function test_cursor_pagination_walks_all_rows_without_duplicates(): void
    {
        $t = TestData::tenant();
        $created = [];
        foreach (range(1, 7) as $i) {
            $created[] = TestData::facility($t, sprintf('f%02d', $i))->id;
            usleep(1500); // distinct UUIDv7 milliseconds
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $req = Request::create('/x', 'GET', array_filter(['limit' => 3, 'cursor' => $cursor]));
            $page = CursorPage::paginate(FacilityUnit::query()->where('site_id', $t['site']), $req);
            $out = $page->toArray(fn ($f) => $f->id);
            array_push($seen, ...$out['items']);
            $cursor = $out['nextCursor'];
            $pages++;
        } while ($cursor !== null);

        $this->assertSame(3, $pages);
        $this->assertSame($created, $seen);
    }

    public function test_cursor_pagination_by_other_column_desc_and_bad_cursor(): void
    {
        $t = TestData::tenant();
        foreach (['b', 'd', 'a', 'c'] as $code) {
            TestData::facility($t, $code);
        }
        $req = Request::create('/x', 'GET', ['limit' => 2]);
        $p1 = CursorPage::paginate(FacilityUnit::query()->where('site_id', $t['site']), $req, 'code', 'desc');
        $this->assertSame(['d', 'c'], $p1->items->pluck('code')->all());
        $this->assertTrue($p1->hasMore);

        $req2 = Request::create('/x', 'GET', ['limit' => 2, 'cursor' => $p1->nextCursor]);
        $p2 = CursorPage::paginate(FacilityUnit::query()->where('site_id', $t['site']), $req2, 'code', 'desc');
        $this->assertSame(['b', 'a'], $p2->items->pluck('code')->all());
        $this->assertFalse($p2->hasMore);
        $this->assertNull($p2->nextCursor);

        $this->expectException(ApiProblem::class);
        CursorPage::paginate(FacilityUnit::query(), Request::create('/x', 'GET', ['cursor' => 'garbage']));
    }
}
