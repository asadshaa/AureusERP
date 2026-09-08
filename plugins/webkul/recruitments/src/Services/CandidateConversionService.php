<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;

class CandidateConversionService
{
    public function convert(Applicant $application): ?Employee
    {
        return DB::transaction(function () use ($application): ?Employee {
            $application = Applicant::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $candidate = Candidate::query()->whereKey($application->candidate_id)->lockForUpdate()->firstOrFail();
            if (! $candidate->partner_id) {
                return null;
            }
            if ($candidate->employee_id) {
                $employee = Employee::query()->findOrFail($candidate->employee_id);
                if ((int) $employee->company_id !== (int) $application->company_id) {
                    throw new RuntimeException('The candidate is already linked to an employee in another company.');
                }

                return $employee;
            }
            if ((int) $candidate->company_id !== (int) $application->company_id) {
                throw new RuntimeException('Candidate and application companies do not match.');
            }
            if ($application->department_id && ! DB::table('employees_departments')
                ->where('id', $application->department_id)
                ->where('company_id', $application->company_id)
                ->exists()) {
                throw new RuntimeException('The application department does not belong to the application company.');
            }
            if ($application->job_id && ! DB::table('employees_job_positions')
                ->where('id', $application->job_id)
                ->where('company_id', $application->company_id)
                ->exists()) {
                throw new RuntimeException('The application job does not belong to the application company.');
            }

            // The job position a candidate is hired into is the natural
            // source of who manages them and which department they land in
            // — JobPosition::manager_id and ::department_id exist precisely
            // for this. Previously neither was consulted: a converted
            // employee always got parent_id = null and, whenever the
            // application itself didn't carry its own department_id,
            // department_id = null too. That silently left every new hire
            // outside everyone's HR hierarchy — invisible to their own
            // manager, and even to whoever just converted them, unless that
            // person separately held hr_view_all_records.
            $employee = Employee::query()->create([
                'name'              => $candidate->name,
                'job_id'            => $application->job_id,
                'department_id'     => $application->department_id ?? $application->job?->department_id,
                'parent_id'         => $application->job?->manager_id,
                'company_id'        => $application->company_id,
                'partner_id'        => $candidate->partner_id,
                'work_email'        => $candidate->email_from,
                'mobile_phone'      => $candidate->phone,
                'joining_date'      => $application->date_closed ?? now()->toDateString(),
                'employment_status' => 'active',
                'is_active'         => true,
            ]);

            $candidate->update(['employee_id' => $employee->id]);
            $application->setAsHired();

            return $employee;
        });
    }
}
