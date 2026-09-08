<?php

namespace Webkul\TimeOff\Services;

use Illuminate\Support\Carbon;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class LeaveApprovalService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    public function submit(Leave $leave, User $requester): ApprovalRequest
    {
        $leave->loadMissing('employee.user');
        if (! $leave->employee || (int) $leave->employee->company_id !== (int) $leave->company_id) {
            throw new RuntimeException('The leave employee does not belong to the leave company.');
        }
        if (
            (int) $leave->employee->user_id !== (int) $requester->id
            && ! $requester->can('hr_approve_leave')
        ) {
            throw new RuntimeException('A user can only submit leave for their own HR hierarchy.');
        }
        if (! in_array($leave->state, [State::CONFIRM, State::REFUSE], true)) {
            throw new RuntimeException('Only a new or refused leave request can be submitted.');
        }
        $this->assertAllocationBalance($leave);

        // Hierarchy-route approval steps (e.g. "requester manager") resolve
        // against whoever is recorded as the ApprovalRequest's requester. A
        // manager or HR user is allowed to submit on an employee's behalf
        // (the check above), but if we recorded *them* as the requester,
        // hierarchy routing would resolve against *their* manager instead of
        // the leave owner's — silently leaving the request unapprovable by
        // anyone. Anchor the requester to the leave's own employee whenever
        // they have a linked user account, regardless of who clicked submit.
        $approval = $this->approvals->submit(
            $leave,
            $leave->employee->user ?? $requester,
            'leave_request',
            null,
            [
                'company_id'    => (int) $leave->company_id,
                'employee_id'   => (int) $leave->employee_id,
                'department_id' => $leave->department_id,
                'team_id'       => $leave->employee->team_id,
                'leave_type_id' => $leave->holiday_status_id,
                'days'          => (string) $leave->number_of_days,
            ],
        );

        $leave->update([
            'approval_request_id' => $approval->id,
            'state'               => State::CONFIRM,
            'submitted_at'        => now(),
            'approved_at'         => null,
            'rejected_at'         => null,
            'rejection_reason'    => null,
        ]);

        return $approval;
    }

    public function approve(Leave $leave, User $actor, ?string $reason = null): Leave
    {
        $approval = $leave->approvalRequest
            ?? throw new RuntimeException('The leave request has not been submitted.');
        $this->approvals->approve($approval, $actor, $reason);

        return $this->synchronize($leave);
    }

    public function reject(Leave $leave, User $actor, string $reason): Leave
    {
        $approval = $leave->approvalRequest
            ?? throw new RuntimeException('The leave request has not been submitted.');
        $this->approvals->reject($approval, $actor, $reason);

        return $this->synchronize($leave);
    }

    public function synchronize(Leave $leave): Leave
    {
        $leave->load('approvalRequest.decisions.actor.employee');
        $approval = $leave->approvalRequest;
        if (! $approval) {
            return $leave;
        }

        $firstApprover = $approval->decisions->first()?->actor?->employee;
        $lastApprover = $approval->decisions->last()?->actor?->employee;
        if ($approval->status === 'approved') {
            $leave->update([
                'state'              => State::VALIDATE_TWO,
                'first_approver_id'  => $firstApprover?->id,
                'second_approver_id' => $lastApprover?->id,
                'approved_at'        => $approval->completed_at ?? now(),
                'rejected_at'        => null,
                'rejection_reason'   => null,
            ]);
        } elseif ($approval->status === 'rejected') {
            $leave->update([
                'state'              => State::REFUSE,
                'first_approver_id'  => $firstApprover?->id,
                'second_approver_id' => $lastApprover?->id,
                'approved_at'        => null,
                'rejected_at'        => $approval->completed_at ?? now(),
                'rejection_reason'   => $approval->decisions->last()?->reason,
            ]);
        } elseif ($approval->decisions->isNotEmpty()) {
            $leave->update([
                'state'             => State::VALIDATE_ONE,
                'first_approver_id' => $firstApprover?->id,
            ]);
        }

        return $leave->fresh(['approvalRequest.decisions']);
    }

    /**
     * The only balance check in this module (TimeOffHelper::
     * handleLeaveAllocation()) runs exclusively inside the Filament Create/
     * Edit page lifecycle — it's never reached from this service, so the
     * "Submit for approval" table action (visible for a REFUSE-state leave)
     * could push a refused request straight back to CONFIRM, and a
     * subsequent approve() straight to VALIDATE_TWO, with zero balance
     * validation at either step. That let a refused leave be resubmitted
     * and approved even after the employee's available balance had since
     * dropped below the requested days. Mirrors handleLeaveAllocation()'s
     * own logic so both entry points enforce the same rule.
     */
    private function assertAllocationBalance(Leave $leave): void
    {
        $leaveType = LeaveType::find($leave->holiday_status_id);
        // requires_allocation is stored as the plain string 'yes'/'no' (no
        // enum cast on the model) — 'no' is PHP-truthy, so a bare `!`
        // check on it never actually detects "does not require allocation".
        // Compare against the enum's own value explicitly instead.
        if (! $leaveType || $leaveType->requires_allocation !== \Webkul\TimeOff\Enums\RequiresAllocation::YES->value) {
            return;
        }

        $endOfYear = Carbon::now()->endOfYear();
        $totalAllocated = LeaveAllocation::where('employee_id', $leave->employee_id)
            ->where('holiday_status_id', $leave->holiday_status_id)
            ->where('state', State::VALIDATE_TWO->value)
            ->where(function ($query) use ($endOfYear) {
                $query->where('date_to', '<=', $endOfYear)->orWhereNull('date_to');
            })
            ->sum('number_of_days');

        $totalTaken = Leave::where('employee_id', $leave->employee_id)
            ->where('holiday_status_id', $leave->holiday_status_id)
            ->where('id', '!=', $leave->id)
            ->where('state', '!=', State::REFUSE->value)
            ->sum('number_of_days');

        $availableBalance = round($totalAllocated - $totalTaken, 1);

        if ($totalAllocated <= 0) {
            throw new RuntimeException("No active allocation exists for {$leaveType->name}.");
        }
        if ((float) $leave->number_of_days > $availableBalance) {
            throw new RuntimeException(
                "Insufficient balance: {$leave->number_of_days} day(s) requested, {$availableBalance} available."
            );
        }
    }
}
