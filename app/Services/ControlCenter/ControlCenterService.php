<?php

namespace App\Services\ControlCenter;

use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\Timesheet\Models\Timesheet;

class ControlCenterService
{
    /**
     * Get the full operations summary for the company.
     *
     * @return array{
     *     company: array{id: int, name: string},
     *     timestamp: string,
     *     health_matrix: array<int, array<string, mixed>>,
     *     attention_items: array<int, array<string, mixed>>,
     *     statistics: array<string, mixed>
     * }
     */
    public function getOperationsSummary(int $companyId): array
    {
        $company = Company::find($companyId);
        $companyName = $company?->name ?? 'Primary Company';
        $today = now()->toDateString();

        $matrix = $this->getHealthMatrix($companyId, $today);
        $attentionItems = $this->getAttentionItems($companyId, $today);

        $totalAttention = count($attentionItems);
        $criticalCount = count(array_filter($matrix, fn ($item): bool => $item['status_level'] === 'danger'));
        $warningCount = count(array_filter($matrix, fn ($item): bool => $item['status_level'] === 'warning'));
        $healthyCount = count(array_filter($matrix, fn ($item): bool => $item['status_level'] === 'success'));

        return [
            'company' => [
                'id'   => $companyId,
                'name' => $companyName,
            ],
            'timestamp'       => now()->format('l, d M Y · h:i A'),
            'health_matrix'   => $matrix,
            'attention_items' => $attentionItems,
            'statistics'      => [
                'total_areas'      => count($matrix),
                'healthy_count'    => $healthyCount,
                'warning_count'    => $warningCount,
                'critical_count'   => $criticalCount,
                'attention_needed' => $totalAttention,
            ],
        ];
    }

    /**
     * Build the Live Operations Health Matrix across all 8 ERP domains.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHealthMatrix(int $companyId, string $today): array
    {
        $matrix = [];

        // 1. Attendance Domain
        $totalActiveEmployees = Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('employment_status', 'active')
            ->count();

        $presentToday = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', $today)
            ->whereNotNull('check_in')
            ->count();

        $attendanceExceptions = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', $today)
            ->where('verification_status', 'needs_review')
            ->count();

        $missingAttendance = max(0, $totalActiveEmployees - $presentToday);

        $attendanceStatus = 'success';
        $attendanceBadge = 'Healthy';
        if ($attendanceExceptions > 0) {
            $attendanceStatus = 'warning';
            $attendanceBadge = 'Action Required';
        }

        $matrix[] = [
            'area'         => 'Attendance',
            'icon'         => 'heroicon-o-clock',
            'status_level' => $attendanceStatus,
            'status_label' => $attendanceBadge,
            'details'      => "{$presentToday} Present / {$missingAttendance} Unchecked".($attendanceExceptions > 0 ? " · {$attendanceExceptions} Flagged" : ''),
            'url'          => url('/admin/attendance-records'),
            'action_label' => 'View Attendance',
        ];

        // 2. Leave / Time-Off Domain
        $pendingLeaves = Leave::query()
            ->where('company_id', $companyId)
            ->whereIn('state', ['confirm', 'validate_one'])
            ->count();

        $pendingAllocations = LeaveAllocation::query()
            ->where('company_id', $companyId)
            ->whereIn('state', ['confirm', 'validate_one'])
            ->count();

        $totalLeavePending = $pendingLeaves + $pendingAllocations;
        $leaveStatus = $totalLeavePending > 0 ? 'warning' : 'success';

        $matrix[] = [
            'area'         => 'Leave & Time-Off',
            'icon'         => 'heroicon-o-calendar-days',
            'status_level' => $leaveStatus,
            'status_label' => $totalLeavePending > 0 ? 'Pending Approvals' : 'All Clear',
            'details'      => $totalLeavePending > 0 ? "{$totalLeavePending} request(s) awaiting approval" : 'No pending leave requests',
            'url'          => url('/admin/time-off/time-offs'),
            'action_label' => 'Review Leaves',
        ];

        // 3. Timesheets Domain
        $pendingTimesheets = Timesheet::query()
            ->where('company_id', $companyId)
            ->where('workflow_status', 'submitted')
            ->count();

        $timesheetStatus = $pendingTimesheets > 0 ? 'warning' : 'success';

        $matrix[] = [
            'area'         => 'Timesheets',
            'icon'         => 'heroicon-o-table-cells',
            'status_level' => $timesheetStatus,
            'status_label' => $pendingTimesheets > 0 ? 'Pending Submission' : 'Up to Date',
            'details'      => $pendingTimesheets > 0 ? "{$pendingTimesheets} sheet(s) submitted awaiting manager review" : 'All submitted timesheets processed',
            'url'          => url('/admin/timesheets'),
            'action_label' => 'View Timesheets',
        ];

        // 4. Accounting & General Ledger
        $draftMoves = Move::query()
            ->where('company_id', $companyId)
            ->where('state', 'draft')
            ->count();

        $draftInvoices = Move::query()
            ->where('company_id', $companyId)
            ->where('state', 'draft')
            ->whereIn('move_type', ['out_invoice', 'in_invoice'])
            ->count();

        $accountingStatus = $draftInvoices > 5 ? 'warning' : 'success';

        $matrix[] = [
            'area'         => 'Accounting & Ledger',
            'icon'         => 'heroicon-o-scale',
            'status_level' => $accountingStatus,
            'status_label' => $draftInvoices > 0 ? 'Drafts Pending' : 'Posting Clear',
            'details'      => $draftInvoices > 0 ? "{$draftInvoices} unposted invoice(s)/bill(s) · {$draftMoves} total draft move(s)" : 'No unposted invoice bottlenecks',
            'url'          => url('/admin/accounting/invoices'),
            'action_label' => 'Go to Invoices',
        ];

        // 5. Bank Reconciliation & Transaction Mapping
        $unmatchedMappings = BankTransactionMapping::query()
            ->where('company_id', $companyId)
            ->whereIn('review_status', [
                BankReviewStatus::Unmapped,
                BankReviewStatus::Suggested,
                BankReviewStatus::NeedsReview,
            ])
            ->count();

        $bankStatus = $unmatchedMappings > 0 ? 'warning' : 'success';

        $matrix[] = [
            'area'         => 'Bank Reconciliation',
            'icon'         => 'heroicon-o-arrows-right-left',
            'status_level' => $bankStatus,
            'status_label' => $unmatchedMappings > 0 ? 'Unmatched Items' : 'Balanced',
            'details'      => $unmatchedMappings > 0 ? "{$unmatchedMappings} transaction(s) require review / mapping" : 'All imported statement lines matched',
            'url'          => url('/admin/accounting/bank-transaction-mappings'),
            'action_label' => 'View Mapping',
        ];

        // 6. Data Imports
        $failedImports = class_exists(ImportRun::class)
            ? ImportRun::query()->where('company_id', $companyId)->where('status', 'failed')->count()
            : 0;

        $importStatus = $failedImports > 0 ? 'danger' : 'success';

        $matrix[] = [
            'area'         => 'Data & Statement Imports',
            'icon'         => 'heroicon-o-arrow-down-tray',
            'status_level' => $importStatus,
            'status_label' => $failedImports > 0 ? 'Failed Runs' : 'Healthy',
            'details'      => $failedImports > 0 ? "{$failedImports} import run(s) failed validation" : 'Recent imports executed successfully',
            'url'          => url('/admin/accounting/import-bank-statement'),
            'action_label' => 'Import Center',
        ];

        // 7. Multi-Step Approvals Engine
        $pendingApprovals = ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();

        $pendingEmployeeRequests = EmployeeRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending_approval')
            ->count();

        $approvalStatus = ($pendingApprovals + $pendingEmployeeRequests) > 0 ? 'warning' : 'success';

        $matrix[] = [
            'area'         => 'Approvals Queue',
            'icon'         => 'heroicon-o-shield-check',
            'status_level' => $approvalStatus,
            'status_label' => $pendingApprovals > 0 ? 'Action Queue' : 'Zero Pending',
            'details'      => "{$pendingApprovals} workflow request(s) · {$pendingEmployeeRequests} employee request(s)",
            'url'          => url('/admin/approval-requests'),
            'action_label' => 'Action Center',
        ];

        // 8. System & Queue Health
        $failedJobs = 0;
        $dbStatus = 'Connected';
        try {
            $failedJobs = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            $failedJobs = 0;
        }

        $systemStatus = $failedJobs > 0 ? 'danger' : 'success';

        $matrix[] = [
            'area'         => 'System & Background Jobs',
            'icon'         => 'heroicon-o-cpu-chip',
            'status_level' => $systemStatus,
            'status_label' => $failedJobs > 0 ? 'Jobs Attention' : 'Optimal',
            'details'      => $failedJobs > 0 ? "{$failedJobs} failed background queue job(s) detected" : 'Queue daemon and database operating normally',
            'url'          => url('/admin'),
            'action_label' => 'System Status',
        ];

        return $matrix;
    }

    /**
     * Build the Attention Required incident queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttentionItems(int $companyId, string $today): array
    {
        $items = [];

        // Check Attendance Exceptions
        $attendanceExceptions = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', $today)
            ->where('verification_status', 'needs_review')
            ->count();

        if ($attendanceExceptions > 0) {
            $items[] = [
                'id'           => 'att_exceptions',
                'title'        => "{$attendanceExceptions} Attendance Exception(s)",
                'urgency'      => 'warning',
                'icon'         => 'heroicon-o-exclamation-triangle',
                'description'  => 'Employee punches flagged for geofence distance or low accuracy awaiting HR verification.',
                'action_label' => 'Review Punches →',
                'url'          => url('/admin/attendance-records?tableFilters[verification_status][value]=needs_review'),
            ];
        }

        // Check Pending Leaves
        $pendingLeaves = Leave::query()
            ->where('company_id', $companyId)
            ->whereIn('state', ['confirm', 'validate_one'])
            ->count();

        if ($pendingLeaves > 0) {
            $items[] = [
                'id'           => 'leave_pending',
                'title'        => "{$pendingLeaves} Leave Request(s) Awaiting Approval",
                'urgency'      => 'warning',
                'icon'         => 'heroicon-o-calendar-days',
                'description'  => 'Employees have submitted time-off requests waiting for manager or HR confirmation.',
                'action_label' => 'Approve Leaves →',
                'url'          => url('/admin/time-off/time-offs'),
            ];
        }

        // Check Pending Timesheets
        $pendingTimesheets = Timesheet::query()
            ->where('company_id', $companyId)
            ->where('workflow_status', 'submitted')
            ->count();

        if ($pendingTimesheets > 0) {
            $items[] = [
                'id'           => 'timesheets_pending',
                'title'        => "{$pendingTimesheets} Timesheet(s) Awaiting Review",
                'urgency'      => 'warning',
                'icon'         => 'heroicon-o-clock',
                'description'  => 'Weekly or daily project work logs submitted for manager review.',
                'action_label' => 'Review Timesheets →',
                'url'          => url('/admin/timesheets'),
            ];
        }

        // Check Draft Invoices / Bills
        $draftInvoices = Move::query()
            ->where('company_id', $companyId)
            ->where('state', 'draft')
            ->whereIn('move_type', ['out_invoice', 'in_invoice'])
            ->count();

        if ($draftInvoices > 0) {
            $items[] = [
                'id'           => 'draft_invoices',
                'title'        => "{$draftInvoices} Draft Invoice(s) / Bill(s)",
                'urgency'      => 'info',
                'icon'         => 'heroicon-o-document-text',
                'description'  => 'Commercial invoices or vendor bills created but not yet posted to the General Ledger.',
                'action_label' => 'Post Invoices →',
                'url'          => url('/admin/accounting/invoices'),
            ];
        }

        // Check Unmatched Bank Transactions
        $unmatchedMappings = BankTransactionMapping::query()
            ->where('company_id', $companyId)
            ->whereIn('review_status', [
                BankReviewStatus::Unmapped,
                BankReviewStatus::Suggested,
                BankReviewStatus::NeedsReview,
            ])
            ->count();

        if ($unmatchedMappings > 0) {
            $items[] = [
                'id'           => 'bank_unmatched',
                'title'        => "{$unmatchedMappings} Unmatched Bank Transaction(s)",
                'urgency'      => 'warning',
                'icon'         => 'heroicon-o-arrows-right-left',
                'description'  => 'Bank statement lines imported without corresponding GL accounts or invoice matches.',
                'action_label' => 'Map Transactions →',
                'url'          => url('/admin/accounting/bank-transaction-mappings'),
            ];
        }

        // Check Failed Imports
        if (class_exists(ImportRun::class)) {
            $failedImports = ImportRun::query()->where('company_id', $companyId)->where('status', 'failed')->count();
            if ($failedImports > 0) {
                $items[] = [
                    'id'           => 'failed_imports',
                    'title'        => "{$failedImports} Failed Import Run(s)",
                    'urgency'      => 'danger',
                    'icon'         => 'heroicon-o-x-circle',
                    'description'  => 'Bank statement or data import files rejected during parsing or validation.',
                    'action_label' => 'Inspect Imports →',
                    'url'          => url('/admin/accounting/import-bank-statement'),
                ];
            }
        }

        // Check Multi-Step Approvals
        $pendingApprovals = ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();

        if ($pendingApprovals > 0) {
            $items[] = [
                'id'           => 'pending_approvals',
                'title'        => "{$pendingApprovals} Multi-Step Approval Request(s)",
                'urgency'      => 'warning',
                'icon'         => 'heroicon-o-shield-check',
                'description'  => 'Purchase orders, expense claims, or operational approvals waiting in approval engine queues.',
                'action_label' => 'Action Center →',
                'url'          => url('/admin/approval-requests'),
            ];
        }

        // Check Failed Queue Jobs
        try {
            $failedJobs = DB::table('failed_jobs')->count();
            if ($failedJobs > 0) {
                $items[] = [
                    'id'           => 'failed_jobs',
                    'title'        => "{$failedJobs} Failed Background Queue Job(s)",
                    'urgency'      => 'danger',
                    'icon'         => 'heroicon-o-cpu-chip',
                    'description'  => 'Background tasks encountered unhandled exceptions during execution.',
                    'action_label' => 'Inspect System →',
                    'url'          => url('/admin'),
                ];
            }
        } catch (Throwable) {
            // ignore if table absent
        }

        return $items;
    }
}
