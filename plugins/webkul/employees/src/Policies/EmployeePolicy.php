<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Models\User;

class EmployeePolicy
{
    use HandlesAuthorization;

    public function __construct(protected HrHierarchyService $hierarchy) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_employee_employee');
    }

    /**
     * Determine whether the user can view the model.
     *
     * Record-level check added: the "employee_employee" permission alone
     * only says the user may view *some* employee record, not this one.
     * Scope is delegated to HrHierarchyService, the single authority for
     * HR visibility (company + reporting hierarchy + department/team
     * management), rather than the generic HasScopedPermissions trait,
     * which keyed on the `coach` relation — a different (and, for GROUP
     * permission, crash-prone) notion of "my people" than the hierarchy
     * this policy is meant to enforce.
     */
    public function view(User $user, Employee $employee): bool
    {
        if (! $user->can('view_employee_employee')) {
            return false;
        }

        return $this->hierarchy->canManage($user, $employee);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_employee_employee');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Employee $employee): bool
    {
        if (! $user->can('update_employee_employee')) {
            return false;
        }

        return $this->hierarchy->canManage($user, $employee);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        if (! $user->can('delete_employee_employee')) {
            return false;
        }

        return $this->hierarchy->canManage($user, $employee);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_employee_employee');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        if (! $user->can('force_delete_employee_employee')) {
            return false;
        }

        return $this->hierarchy->canManage($user, $employee);
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_employee_employee');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Employee $employee): bool
    {
        if (! $user->can('restore_employee_employee')) {
            return false;
        }

        return $this->hierarchy->canManage($user, $employee);
    }
}
