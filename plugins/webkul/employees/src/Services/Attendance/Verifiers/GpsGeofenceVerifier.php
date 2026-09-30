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
use Webkul\Employee\Services\Attendance\GeofenceEvaluator;

class GpsGeofenceVerifier implements AttendanceVerifier
{
    public function __construct(private readonly GeofenceEvaluator $evaluator) {}

    public function method(): AttendanceVerificationMethod
    {
        return AttendanceVerificationMethod::Gps;
    }

    public function verify(
        Employee $employee,
        ?LocationEvidence $evidence,
        Collection $candidates,
        AttendanceVerificationAction $action,
        bool $repeatedCoordinates = false,
    ): GeofenceDecision {
        if ($evidence === null) {
            return new GeofenceDecision(AttendanceVerificationResult::InvalidCoordinates, false);
        }

        return $this->evaluator->evaluate($evidence, $candidates, $action, $repeatedCoordinates);
    }
}
