<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Models\WorkLocation;

final readonly class GeofenceDecision
{
    /** @param array<int, string> $flags */
    public function __construct(
        public AttendanceVerificationResult $result,
        public bool $accepted,
        public bool $needsReview = false,
        public ?WorkLocation $matchedLocation = null,
        public ?float $distanceMeters = null,
        public array $flags = [],
    ) {}
}
