<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\FacilityUnit;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Site;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class UuidModelTest extends TestCase
{
    public function test_binary_uuid_round_trips_and_is_16_bytes_in_db(): void
    {
        $org = Organization::create(['name' => 'Acme']);

        $this->assertTrue(Ids::isUuid($org->id));
        $raw = DB::table('organization')->where('id', Ids::toBinary($org->id))->value('id');
        $this->assertSame(16, strlen($raw));
        $this->assertSame($org->id, Organization::query()->find($org->id)->id);
        $this->assertSame($org->id, Organization::query()->find(strtoupper($org->id))->id);
        $this->assertSame($org->id, Organization::query()->where('id', $org->id)->firstOrFail()->id);
        $this->assertCount(1, Organization::query()->whereIn('id', [$org->id, Ids::uuid7()])->get());
        $this->assertSame($org->id, json_decode($org->toJson())->id); // serialises as canonical string
    }

    public function test_client_supplied_id_is_kept(): void
    {
        $id = Ids::uuid7();
        $this->assertSame($id, Organization::create(['id' => $id, 'name' => 'X'])->id);
    }

    public function test_relations_work_with_canonical_strings(): void
    {
        $t = TestData::tenant();
        $parent = TestData::facility($t, 'restaurant');
        $child = TestData::facility($t, 'kitchen', $parent->id);

        $this->assertSame($parent->id, FacilityUnit::query()->find($child->id)->parent->id);
        $this->assertSame([$child->id], $parent->children()->pluck('id')->all());
        $this->assertSame([$parent->id], FacilityUnit::query()->with('children')->whereKey($parent->id)->get()->pluck('id')->all());
        $this->assertSame($t['site'], Site::query()->find($t['site'])->id);
        $this->assertSame($t['org'], Site::query()->where(['organization_id' => $t['org']])->firstOrFail()->organization_id);
    }

    public function test_invalid_uuid_is_rejected_on_assignment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Site::create(['organization_id' => 'not-a-uuid', 'name' => 'x']);
    }
}
