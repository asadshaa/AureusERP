<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Carbon\CarbonImmutable;

/** Raw, untrusted browser location evidence. Nothing here is a decision. */
final readonly class LocationEvidence
{
    /**
     * @param  ?CarbonImmutable  $capturedAt  when the device says the fix was taken (Position.timestamp)
     * @param  ?CarbonImmutable  $clientNow  the device clock when the fix was sent, so the reading's age
     *                                       can be measured on one clock regardless of device clock skew
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public float $accuracy,
        public ?CarbonImmutable $capturedAt,
        public string $clientRequestId,
        public ?CarbonImmutable $clientNow = null,
    ) {}
}
