<?php

namespace App\Domain\Booking\Contracts;

use RuntimeException;

/** Thrown by a CloudBookingAuthority implementation when Cloud cannot be reached (timeout, DNS, 5xx). */
class CloudUnreachableException extends RuntimeException {}
