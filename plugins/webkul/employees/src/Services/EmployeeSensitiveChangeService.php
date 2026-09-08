<?php

namespace Webkul\Employee\Services;

use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class EmployeeSensitiveChangeService
{
    private const FIELDS = [
        'identification_id',
        'passport_id',
        'ssnid',
        'sinid',
        'bank_account_id',
        'salary_grade',
        'base_salary',
        'salary_currency_id',
    ];

    public function __construct(protected ApprovalEngine $approvals) {}

    /** @param array<string, mixed> $changes */
    public function submit(Employee $employee, User $requester, array $changes): ApprovalRequest
    {
        $changes = array_intersect_key($changes, array_flip(self::FIELDS));
        if ($changes === []) {
            throw new RuntimeException('No supported sensitive employee changes were supplied.');
        }

        // Hierarchy-route approval steps (e.g. "department manager") resolve
        // against whoever is recorded as the ApprovalRequest's requester. An
        // HR admin requests this change (gated by
        // hr_manage_sensitive_employee_data, not by being the affected
        // employee), but if we recorded *them* as the requester, hierarchy
        // routing would resolve against *their* department/manager instead
        // of the affected employee's — silently leaving it unapprovable by
        // anyone, or routed to the wrong approver. Anchor to the affected
        // employee's own user whenever they have one. Same fix as
        // LeaveApprovalService::submit() / TimesheetWorkflowService::submit()
        // / EmployeeRequestService::submit().
        return $this->approvals->submit(
            $employee,
            $employee->user ?? $requester,
            'employee_sensitive_change',
            isset($changes['base_salary']) ? (string) $changes['base_salary'] : null,
            [
                'company_id'      => (int) $employee->company_id,
                'employee_id'     => (int) $employee->id,
                'department_id'   => $employee->department_id,
                'previous_values' => $employee->only(array_keys($changes)),
                'new_values'      => $changes,
            ],
        );
    }

    public function applyApproved(ApprovalRequest $request): Employee
    {
        if ($request->request_type !== 'employee_sensitive_change' || $request->status !== 'approved') {
            throw new RuntimeException('Only approved employee sensitive-change requests can be applied.');
        }

        $employee = Employee::query()
            ->whereKey($request->subject_id)
            ->where('company_id', $request->company_id)
            ->firstOrFail();
        $changes = array_intersect_key((array) data_get($request->context, 'new_values', []), array_flip(self::FIELDS));
        $employee->update($changes);

        return $employee->fresh();
    }
}
