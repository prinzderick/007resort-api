<?php

namespace App\Domain\Payments\Support;

/** A provider-confirmed collection can no longer be applied to its order (balance/state changed). Needs a human refund decision. */
final class CollectionCaptureConflict extends \RuntimeException {}
