<?php

namespace Webkul\TimeOff\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Models\User;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

class LeavePolicy
{
    use HandlesAuthorization;

    public function __construct(protected HrHierarchyService $hierarchy) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_time_off_time::off');
    }

    /**
     * Determine whether the user can view the model.
     *
     * Record-level check added: previously any user with the list-level
     * permission could view any company's leave record by id.
     */
    public function view(User $user, Leave $leave): bool
    {
        if (! $user->can('view_time_off_time::off')) {
            return false;
        }

        return $leave->employee && $this->hierarchy->canManage($user, $leave->employee);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_time_off_time::off');
    }

    /**
     * Determine whether the user can update the model.
     *
     * Scope check moved from HasScopedPermissions::hasAccess($user, $leave,
     * 'employee') to HrHierarchyService. The trait compared
     * $leave->employee->id === $user->id — an Employee id against a User
     * id, different id spaces that only coincide by chance — and, for a
     * GROUP-permission user, dereferenced Employee::teams(), a relation
     * that does not exist on the Employee model, which would throw. Both
     * defects are avoided by using the hierarchy service, which already
     * resolves an employee's own record correctly (self is always in its
     * own visible set) and is company-aware.
     */
    public function update(User $user, Leave $leave): bool
    {
        if (! $user->can('update_time_off_time::off')) {
            return false;
        }

        return $leave->approvalRequest?->status !== 'pending'
            && $leave->state !== State::VALIDATE_TWO
            && $leave->employee
            && $this->hierarchy->canManage($user, $leave->employee);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Leave $leave): bool
    {
        if (! $user->can('delete_time_off_time::off')) {
            return false;
        }

        return $leave->approvalRequest?->status !== 'pending'
            && $leave->state !== State::VALIDATE_TWO
            && $leave->employee
            && $this->hierarchy->canManage($user, $leave->employee);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_time_off_time::off');
    }
}
