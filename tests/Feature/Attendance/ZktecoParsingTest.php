<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Adapters\BiometricAdapters;
use App\Domain\Attendance\Adapters\JsonPushAdapter;
use App\Domain\Attendance\Adapters\ZktecoAdmsAdapter;
use App\Domain\Attendance\Models\AttendanceDevice;
use PHPUnit\Framework\TestCase;

/** Pure parsing tests against captured-format ATTLOG fixtures (no DB). */
class ZktecoParsingTest extends TestCase
{
    private function device(string $tz = 'Africa/Lagos'): AttendanceDevice
    {
        $d = new AttendanceDevice;
        $d->forceFill(['serial_number' => 'ZK-TEST-001', 'time_zone' => $tz, 'adapter' => 'ZKTECO_ADMS']);

        return $d;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/Attendance/'.$name);
    }

    public function test_tab_separated_attlog_is_parsed_and_converted_to_utc(): void
    {
        $p = (new ZktecoAdmsAdapter)->parsePunches($this->fixture('attlog_tab.txt'), $this->device());

        $this->assertCount(5, $p);
        $this->assertSame('1', $p[0]->terminalUserId);
        $this->assertSame('2026-09-22T07:03:11Z', $p[0]->punchedAt->format('Y-m-d\TH:i:s\Z'), 'Lagos is UTC+1');
        $this->assertSame('IN', $p[0]->direction);
        $this->assertSame('FINGERPRINT', $p[0]->verifyMode);
        $this->assertSame('OUT', $p[1]->direction);
        $this->assertSame('FACE', $p[2]->verifyMode);
        $this->assertSame('CARD', $p[4]->verifyMode);
        $this->assertSame('17', $p[4]->terminalUserId);
    }

    public function test_space_separated_crlf_variant_parses_identically(): void
    {
        $a = (new ZktecoAdmsAdapter)->parsePunches($this->fixture('attlog_spaces_crlf.txt'), $this->device());
        $this->assertCount(2, $a);
        $this->assertSame('2026-09-22 16:12:40', $a[1]->punchedAt->format('Y-m-d H:i:s'));
        $this->assertSame('OUT', $a[1]->direction);
    }

    public function test_malformed_lines_are_skipped_and_unknown_codes_tolerated(): void
    {
        $p = (new ZktecoAdmsAdapter)->parsePunches($this->fixture('attlog_malformed.txt'), $this->device());
        // "garbage", blank and the impossible date are dropped; "6\t2026-09-22 09:00:00" has no status; "7" has unknown status/verify.
        $this->assertSame(['6', '7'], array_map(fn ($x) => $x->terminalUserId, $p));
        $this->assertSame('UNKNOWN', $p[0]->direction);
        $this->assertNull($p[0]->verifyMode);
        $this->assertSame('UNKNOWN', $p[1]->direction);
        $this->assertSame('MODE_77', $p[1]->verifyMode);
    }

    public function test_device_time_zone_is_honoured(): void
    {
        $p = (new ZktecoAdmsAdapter)->parsePunches("9\t2026-09-22 08:00:00\t0\t1\n", $this->device('UTC'));
        $this->assertSame('2026-09-22 08:00:00', $p[0]->punchedAt->format('Y-m-d H:i:s'));
        $p = (new ZktecoAdmsAdapter)->parsePunches("9\t2026-09-22 08:00:00\t0\t1\n", $this->device('America/New_York'));
        $this->assertSame('2026-09-22 12:00:00', $p[0]->punchedAt->format('Y-m-d H:i:s'));
    }

    public function test_handshake_response_has_the_required_options(): void
    {
        $d = $this->device();
        $d->adms_stamp = '9999';
        $body = (new ZktecoAdmsAdapter)->handshake($d);
        $this->assertStringContainsString("GET OPTION FROM: ZK-TEST-001\n", $body);
        $this->assertStringContainsString("ATTLOGStamp=9999\n", $body);
        $this->assertStringContainsString("TransFlag=TransData AttLog\n", $body);
        $this->assertStringContainsString("Realtime=1\n", $body);
    }

    public function test_json_push_adapter_respects_offsets(): void
    {
        $p = (new JsonPushAdapter)->parsePunches(['punches' => [
            ['deviceUserId' => '3', 'punchedAt' => '2026-09-22T08:00:00+01:00', 'rawId' => 'a', 'direction' => 'IN', 'verifyMode' => 'fingerprint'],
            ['deviceUserId' => '3', 'punchedAt' => '2026-09-22T07:00:00Z', 'rawId' => 'b'],
            ['deviceUserId' => '3', 'punchedAt' => '2026-09-22 08:00:00', 'rawId' => 'c'],
        ]], $this->device());
        $this->assertSame('07:00:00', $p[0]->punchedAt->format('H:i:s'));
        $this->assertSame('07:00:00', $p[1]->punchedAt->format('H:i:s'));
        $this->assertSame('07:00:00', $p[2]->punchedAt->format('H:i:s'), 'offset-less falls back to the device zone (Lagos)');
        $this->assertSame('FINGERPRINT', $p[0]->verifyMode);
        $this->assertSame('UNKNOWN', $p[1]->direction);
    }

    public function test_adapter_registry_is_swappable(): void
    {
        $this->assertSame([ZktecoAdmsAdapter::class, JsonPushAdapter::class], array_values(BiometricAdapters::MAP));
        $this->expectException(\InvalidArgumentException::class);
        BiometricAdapters::named('NOPE');
    }
}
