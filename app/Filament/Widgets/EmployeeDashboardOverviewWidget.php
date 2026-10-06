<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class EmployeeDashboardOverviewWidget extends Widget
{
    protected string $view = 'filament.widgets.employee-dashboard-overview';

    protected static bool $isLazy = false;

    protected static ?int $sort = -95;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->employee !== null || Employee::where('user_id', $user->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $employee = $user->employee ?? Employee::with(['department', 'job', 'parent', 'workLocation', 'company'])->where('user_id', $user->id)->first();
        if (! $employee) {
            return [];
        }

        $companyId = (int) ($employee->company_id ?? $user->default_company_id ?? 1);
        $endOfYear = Carbon::now()->endOfYear();

        // 1. Employee Profile Details
        $avatarUrl = $user->avatar ? Storage::disk('public')->url($user->avatar) : null;
        if (! $avatarUrl && $user->partner?->avatar) {
            $avatarUrl = Storage::disk('public')->url($user->partner->avatar);
        }

        $name = $employee->name ?? $user->name;
        $words = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        if (empty($initials)) {
            $initials = 'EM';
        }

        $joiningDate = $employee->joining_date ? Carbon::parse($employee->joining_date) : null;
        $tenure = $joiningDate ? $joiningDate->diffForHumans(null, true) : null;

        $employeeData = [
            'id'              => $employee->id,
            'number'          => $employee->employee_number ?? ('EMP-'.str_pad($employee->id, 4, '0', STR_PAD_LEFT)),
            'name'            => $name,
            'initials'        => $initials,
            'avatar_url'      => $avatarUrl,
            'job_title'       => $employee->job_title ?? $employee->job?->name ?? 'Employee',
            'department'      => $employee->department?->name ?? 'General',
            'manager_name'    => $employee->parent?->name ?? 'Not Assigned',
            'work_email'      => $employee->work_email ?? $user->email,
            'work_phone'      => $employee->work_phone ?? $employee->mobile_phone ?? '—',
            'work_location'   => $employee->workLocation?->name ?? 'Head Office',
            'joining_date'    => $joiningDate?->format('d M Y'),
            'tenure'          => $tenure,
            'employment_type' => ucwords(str_replace('_', ' ', $employee->employment_status ?? $employee->employee_type ?? 'Full Time')),
            'company_name'    => $employee->company?->name ?? $user->defaultCompany?->name ?? config('app.name'),
        ];

        // 2. Leave Types & Balances
        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id')
                    ->orWhere('company_id', $companyId);
            })
            ->orderBy('name')
            ->get();

        $leaveCards = [];
        $totalAllocated = 0.0;
        $totalTaken = 0.0;
        $totalPending = 0.0;

        foreach ($leaveTypes as $type) {
            $allocated = (float) LeaveAllocation::where('employee_id', $employee->id)
                ->where('holiday_status_id', $type->id)
                ->where('state', State::VALIDATE_TWO->value)
                ->where(function ($q) use ($endOfYear) {
                    $q->where('date_to', '<=', $endOfYear)
                        ->orWhereNull('date_to');
                })
                ->sum('number_of_days');

            $taken = (float) Leave::where('employee_id', $employee->id)
                ->where('holiday_status_id', $type->id)
                ->where('state', State::VALIDATE_TWO->value)
                ->sum('number_of_days');

            $pending = (float) Leave::where('employee_id', $employee->id)
                ->where('holiday_status_id', $type->id)
                ->whereIn('state', [State::CONFIRM->value, State::VALIDATE_ONE->value])
                ->sum('number_of_days');

            $left = max(0, round($allocated - $taken, 1));
            $pct = $allocated > 0 ? min(100, max(0, (int) round(($left / $allocated) * 100))) : 0;

            $totalAllocated += $allocated;
            $totalTaken += $taken;
            $totalPending += $pending;

            $lower = strtolower($type->name);
            $icon = 'heroicon-o-calendar';
            $theme = 'primary';

            if (str_contains($lower, 'sick')) {
                $icon = 'heroicon-o-heart';
                $theme = 'danger';
            } elseif (str_contains($lower, 'casual')) {
                $icon = 'heroicon-o-sparkles';
                $theme = 'warning';
            } elseif (str_contains($lower, 'annual') || str_contains($lower, 'vacation')) {
                $icon = 'heroicon-o-sun';
                $theme = 'success';
            } elseif (str_contains($lower, 'parental') || str_contains($lower, 'maternity') || str_contains($lower, 'paternity')) {
                $icon = 'heroicon-o-user-group';
                $theme = 'info';
            } elseif (str_contains($lower, 'training') || str_contains($lower, 'study')) {
                $icon = 'heroicon-o-academic-cap';
                $theme = 'secondary';
            }

            $leaveCards[] = [
                'id'         => $type->id,
                'name'       => $type->name,
                'icon'       => $icon,
                'theme'      => $theme,
                'allocated'  => $allocated,
                'taken'      => $taken,
                'pending'    => $pending,
                'left'       => $left,
                'percentage' => $pct,
            ];
        }

        $totalLeft = max(0, round($totalAllocated - $totalTaken, 1));
        $overallPercentage = $totalAllocated > 0 ? min(100, max(0, (int) round(($totalLeft / $totalAllocated) * 100))) : 0;

        // 3. Recent Leave Requests
        $recentLeaves = Leave::query()
            ->with(['holidayStatus'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->take(4)
            ->get()
            ->map(function (Leave $l): array {
                $type = $l->holidayStatus?->name ?? 'Leave';
                $days = (float) ($l->number_of_days ?? 0);
                $from = $l->request_date_from ? Carbon::parse($l->request_date_from)->format('d M') : ($l->date_from ? Carbon::parse($l->date_from)->format('d M') : '');
                $to = $l->request_date_to ? Carbon::parse($l->request_date_to)->format('d M Y') : ($l->date_to ? Carbon::parse($l->date_to)->format('d M Y') : $from);
                $dateRange = $from === $to ? $from : "{$from} – {$to}";

                $stateValue = $l->state instanceof State ? $l->state->value : (string) $l->state;
                $statusLabel = match ($stateValue) {
                    'validate_two' => 'Approved',
                    'validate_one' => 'Partially Approved',
                    'confirm'      => 'Pending Approval',
                    'refuse'       => 'Rejected',
                    default        => ucfirst($stateValue),
                };

                $statusColor = match ($stateValue) {
                    'validate_two' => 'success',
                    'validate_one' => 'info',
                    'confirm'      => 'warning',
                    'refuse'       => 'danger',
                    default        => 'gray',
                };

                return [
                    'id'           => $l->id,
                    'type'         => $type,
                    'days'         => $days,
                    'date_range'   => $dateRange,
                    'status_label' => $statusLabel,
                    'status_color' => $statusColor,
                ];
            })
            ->all();

        // 4. Action URLs
        $requestLeaveUrl = null;
        try {
            $requestLeaveUrl = MyTimeOffResource::getUrl('create');
        } catch (\Throwable) {
            $requestLeaveUrl = url('/admin/time-off/dashboard/my-time-offs/create');
        }

        $myAttendanceUrl = null;
        try {
            $myAttendanceUrl = MyAttendance::getUrl();
        } catch (\Throwable) {
            $myAttendanceUrl = url('/admin/my-attendance');
        }

        $profileUrl = null;
        try {
            $profileUrl = Profile::getUrl();
        } catch (\Throwable) {
            $profileUrl = url('/admin/settings/profile');
        }

        return [
            'employee'     => $employeeData,
            'leaveSummary' => [
                'total_allocated'    => $totalAllocated,
                'total_taken'        => $totalTaken,
                'total_left'         => $totalLeft,
                'total_pending'      => $totalPending,
                'overall_percentage' => $overallPercentage,
                'cards'              => $leaveCards,
            ],
            'recentLeaves' => $recentLeaves,
            'urls'         => [
                'request_leave' => $requestLeaveUrl,
                'my_attendance' => $myAttendanceUrl,
                'profile'       => $profileUrl,
            ],
        ];
    }
}
