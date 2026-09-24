<?php

namespace Tests\Unit;

use App\Support\Ids;
use PHPUnit\Framework\TestCase;

class IdsTest extends TestCase
{
    public function test_uuid7_is_valid_version_7_and_time_ordered(): void
    {
        $a = Ids::uuid7();
        usleep(2000);
        $b = Ids::uuid7();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertTrue(strcmp(Ids::toBinary($a), Ids::toBinary($b)) < 0, 'binary order must follow creation time');
    }

    public function test_binary_round_trip(): void
    {
        $id = Ids::uuid7();
        $this->assertSame(16, strlen(Ids::toBinary($id)));
        $this->assertSame($id, Ids::fromBinary(Ids::toBinary($id)));
        $this->assertSame($id, Ids::normalize(str_replace('-', '', strtoupper($id))));
    }

    public function test_rejects_garbage(): void
    {
        $this->assertFalse(Ids::isUuid('nope'));
        $this->expectException(\InvalidArgumentException::class);
        Ids::toBinary('nope');
    }
}
