<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;

/**
 * Company isolation and hierarchy scoping come from HrHierarchyService (the
 * same service every other HR screen uses); no second authorization
 * framework is introduced. Raw coordinates/IP need their own permission --
 * being the employee, or their manager, is not enough.
 */
class AttendanceVerificationPolicy
{
    use HandlesAuthorization;

    public function __construct(private readonly HrHierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ViewAttendance) || $user->can(HrPermissions::ManageAttendance);
    }

    public function view(User $user, AttendanceVerification $verification): bool
    {
        $employee = $verification->employee;
        if (! $employee) {
            return false;
        }

        if ((int) $employee->user_id === (int) $user->id && (int) $employee->company_id === (int) $user->default_company_id) {
            return true;
        }

        return $this->viewAny($user) && $this->hierarchy->canManage($user, $employee);
    }

    public function viewLocationEvidence(User $user, AttendanceVerification $verification): bool
    {
        $employee = $verification->employee;

        return $employee !== null
            && $user->can(HrPermissions::ViewAttendanceLocationEvidence)
            && $this->hierarchy->canManage($user, $employee);
    }

    public function review(User $user, AttendanceVerification $verification): bool
    {
        $employee = $verification->employee;

        return $employee !== null
            && $user->can(HrPermissions::ReviewAttendanceVerifications)
            && (int) $employee->user_id !== (int) $user->id
            && $this->hierarchy->canManage($user, $employee);
    }
}
