<?php

namespace Webkul\Employee\Services\Attendance\Contracts;

use Illuminate\Support\Collection;
use Webkul\Employee\Enums\AttendanceVerificationAction;
use Webkul\Employee\Enums\AttendanceVerificationMethod;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\Data\GeofenceDecision;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;

/**
 * The extension seam for attendance verification methods. GPS and
 * remote-exempt exist today; a future QR, GPS+QR, biometric or native-app
 * verifier adds a class and an AttendanceVerificationMethod case -- the
 * orchestrator, tables and UI stay the same.
 */
interface AttendanceVerifier
{
    public function method(): AttendanceVerificationMethod;

    /** @param Collection<int, WorkLocation> $candidates */
    public function verify(
        Employee $employee,
        ?LocationEvidence $evidence,
        Collection $candidates,
        AttendanceVerificationAction $action,
        bool $repeatedCoordinates = false,
    ): GeofenceDecision;
}
