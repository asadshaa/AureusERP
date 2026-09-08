<?php

namespace Webkul\Employee\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;

class PerformanceService
{
    /** @return Collection<int, PerformanceReview> */
    public function launch(PerformanceCycle $cycle, User $actor): Collection
    {
        if ((int) $actor->default_company_id !== (int) $cycle->company_id
            && ! $actor->allowedCompanies()->whereKey($cycle->company_id)->exists()) {
            throw new RuntimeException('The user cannot launch a performance cycle for this company.');
        }

        return DB::transaction(function () use ($cycle): Collection {
            $employees = Employee::query()
                ->where('company_id', $cycle->company_id)
                ->where('is_active', true)
                ->get();

            // A review needs a manager reviewer distinct from the employee
            // being reviewed. Two cases leave no valid candidate: nobody above
            // the employee at all (parent_id null, department has no
            // manager_id), or the only candidate IS the employee (a department
            // head with nobody above them, so department->manager_id resolves
            // to themself). Both used to silently write reviewer_id = null or
            // reviewer_id = employee_id — one made the review permanently
            // unfinishable, the other let the employee "manager-approve"
            // their own review. Escalate either case to whoever holds
            // hr_manage_performance for this company, resolved once per
            // launch so every straggler gets the same deterministic fallback.
            $hrReviewerEmployeeIds = $this->hrReviewerEmployeeIds((int) $cycle->company_id);

            $reviews = $employees->map(function (Employee $employee) use ($cycle, $hrReviewerEmployeeIds): PerformanceReview {
                $reviewerId = $employee->parent_id ?? $employee->department?->manager_id;

                if (! $reviewerId || (int) $reviewerId === (int) $employee->id) {
                    $reviewerId = collect($hrReviewerEmployeeIds)
                        ->first(fn (int $id): bool => $id !== (int) $employee->id);
                }

                return PerformanceReview::query()->firstOrCreate(
                    ['cycle_id' => $cycle->id, 'employee_id' => $employee->id],
                    [
                        'company_id' => $cycle->company_id,
                        'reviewer_id'=> $reviewerId,
                        'status'     => 'self_review',
                    ],
                );
            });
            $cycle->update(['status' => 'active']);

            return $reviews;
        });
    }

    /**
     * Employee IDs (in a stable order) of every user holding
     * hr_manage_performance who has an Employee record in this company —
     * the escalation pool for a review that would otherwise get no valid
     * manager reviewer.
     *
     * @return array<int, int>
     */
    private function hrReviewerEmployeeIds(int $companyId): array
    {
        return User::permission(HrPermissions::ManagePerformance)
            ->join('employees_employees', 'employees_employees.user_id', '=', 'users.id')
            ->where('employees_employees.company_id', $companyId)
            ->whereNull('employees_employees.deleted_at')
            ->orderBy('employees_employees.id')
            ->pluck('employees_employees.id')
            ->all();
    }

    public function submitSelfReview(PerformanceReview $review, Employee $employee, float $rating, ?string $comments = null): PerformanceReview
    {
        if ((int) $review->employee_id !== (int) $employee->id || $review->status !== 'self_review') {
            throw new RuntimeException('This performance review is not available for employee self-review.');
        }
        $review->update([
            'self_rating'  => $rating,
            'self_comments'=> $comments,
            'status'       => 'manager_review',
            'submitted_at' => now(),
        ]);

        return $review->fresh();
    }

    public function completeManagerReview(PerformanceReview $review, Employee $reviewer, float $rating, ?string $comments = null): PerformanceReview
    {
        if (
            (int) $review->reviewer_id !== (int) $reviewer->id
            || (int) $review->employee_id === (int) $reviewer->id
            || $review->status !== 'manager_review'
        ) {
            throw new RuntimeException('This employee is not the assigned manager reviewer.');
        }
        $review->update([
            'manager_rating'  => $rating,
            'manager_comments'=> $comments,
            'status'          => 'completed',
            'completed_at'    => now(),
        ]);

        return $review->fresh();
    }
}
