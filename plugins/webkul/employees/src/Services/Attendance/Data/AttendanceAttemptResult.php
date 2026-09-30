<?php

namespace Webkul\Employee\Services\Attendance\Data;

use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Models\AttendanceRecord;

final readonly class AttendanceAttemptResult
{
    public function __construct(
        public AttendanceVerificationResult $result,
        public bool $accepted,
        public string $message,
        public ?string $localTime = null,
        public ?AttendanceRecord $record = null,
    ) {}
}
