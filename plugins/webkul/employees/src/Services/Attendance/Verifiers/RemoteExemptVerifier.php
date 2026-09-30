<?php

namespace Webkul\Employee\Services\Attendance\Verifiers;

use Illuminate\Support\Collection;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationMethod;
use Webkul\Employee\Enums\AttendanceVerificationResult;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\Contracts\AttendanceVerifier;
use Webkul\Employee\Services\Attendance\Data\GeofenceDecision;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;

/** Remote employees are never asked for, and never have stored, a location. */
class RemoteExemptVerifier implements AttendanceVerifier
{
    public function method(): AttendanceVerificationMethod
    {
        return AttendanceVerificationMethod::None;
    }

    public function verify(
        Employee $employee,
        ?LocationEvidence $evidence,
        Collection $candidates,
        AttendanceVerificationAction $action,
        bool $repeatedCoordinates = false,
    ): GeofenceDecision {
        return new GeofenceDecision(AttendanceVerificationResult::RemoteExempt, true);
    }
}
