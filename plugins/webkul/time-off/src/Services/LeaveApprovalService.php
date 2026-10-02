<?php

namespace Webkul\TimeOff\Services;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Carbon;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

class LeaveApprovalService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    public function submit(Leave $leave, User $requester): ApprovalRequest
    {
        $leave->loadMissing('employee');
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

        $approval = $this->approvals->submit(
            $leave,
            $requester,
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

        $this->notifyApprovers($leave, $requester);

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

    private function notifyApprovers(Leave $leave, User $requester): void
    {
        try {
            $leave->loadMissing(['employee', 'holidayStatus']);
            $employee = $leave->employee;
            if (! $employee) {
                return;
            }

            $parentId = $employee->parent_id;
            $manager = $parentId ? Employee::query()->with('user')->find($parentId)?->user : null;

            $hasManager = $manager && $manager->is_active && (int) $manager->id !== (int) $requester->id;

            if ($hasManager) {
                $recipients = collect([$manager]);
                $routingNote = '[Routed to Line Manager]';
            } else {
                $recipients = User::query()
                    ->where('default_company_id', $leave->company_id)
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $u): bool => (
                        $u->hasRole([
                            'Admin', 'Super Admin', 'hr', 'hr_manager', 'hr manager',
                            'hr_ops_manager', 'hr ops manager', 'hr operations manager',
                            'hr_administrator', 'hr administrator', 'human resources', 'human resources manager',
                        ])
                        || $u->can('hr_approve_leave')
                        || $u->can('hr_view_all_records')
                    ) && (int) $u->id !== (int) $requester->id);
                $routingNote = '[Forwarded to HR: no line manager assigned]';
            }

            if ($recipients->isEmpty()) {
                return;
            }

            $who = $employee->name.((int) $employee->user_id !== (int) $requester->id ? " (by {$requester->name})" : '');
            $leaveTypeName = $leave->holidayStatus?->name ?? 'Leave';
            $startDate = Carbon::parse($leave->request_date_from);
            $endDate = Carbon::parse($leave->request_date_to ?: $leave->request_date_from);

            $dayAndDate = "From {$startDate->format('l, d M Y')} to {$endDate->format('l, d M Y')} ({$leave->number_of_days} days)";
            $reason = $leave->private_name ? " | Note: {$leave->private_name}" : '';

            $title = "Leave Request: {$employee->name}";
            $body = "{$who} submitted {$leaveTypeName} request ({$dayAndDate}){$reason} {$routingNote}";

            $notification = FilamentNotification::make()
                ->warning()
                ->title($title)
                ->body($body);

            foreach ($recipients as $recipient) {
                $recipient->notifyNow($notification->toDatabase());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
