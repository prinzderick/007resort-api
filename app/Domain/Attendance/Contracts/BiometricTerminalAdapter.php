<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Support\ParsedPunch;

/**
 * Hardware seam (architecture/17 §"biometric adapter"): a terminal vendor/protocol is one adapter. Everything below
 * (dedup, staff mapping, attendance_day, sync) is vendor-neutral and only ever sees ParsedPunch.
 * To support a new terminal: implement this, add it to BiometricAdapters::MAP and to the attendance_device.adapter CHECK.
 */
interface BiometricTerminalAdapter
{
    /** Value stored in attendance_device.adapter. */
    public function key(): string;

    /**
     * Parse a vendor payload (raw text body or decoded JSON) into normalised punches with UTC timestamps.
     * Naive (offset-less) terminal local times are interpreted in the device's time zone.
     *
     * @return list<ParsedPunch>
     */
    public function parsePunches(string|array $payload, AttendanceDevice $device): array;
}
