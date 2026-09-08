<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Models\User;

class ApplicantIntakeService
{
    public function __construct(protected ApplicantSourceRegistry $sources) {}

    /** @param array<string, mixed> $payload */
    public function import(string $source, array $payload, User $actor): Applicant
    {
        $data = $this->sources->resolve($source)->normalize($payload);
        $data = Validator::make($data, [
            'company_id'               => ['nullable', 'integer'],
            'external_application_id'  => ['required', 'string', 'max:255'],
            'candidate_name'           => ['required', 'string', 'max:255'],
            'candidate_email'          => ['required', 'email', 'max:255'],
            'candidate_phone'          => ['nullable', 'string', 'max:255'],
            'job_id'                   => ['required', 'integer'],
            'stage_id'                 => ['nullable', 'integer'],
            'source_id'                => ['nullable', 'integer', 'exists:utm_sources,id'],
            'medium_id'                => ['nullable', 'integer', 'exists:utm_mediums,id'],
            'source_details'           => ['required', 'string', 'max:255'],
            'resume_path'              => ['nullable', 'string', 'max:2048'],
            'portfolio_url'            => ['nullable', 'url', 'max:2048'],
        ])->validate();

        // The candidate-dedup lookup below matches on this exact string, and
        // email_from carries no unique DB constraint — a byte-different but
        // logically identical address (different casing, incidental
        // whitespace from an upstream ATS/webhook) previously fell through
        // to creating a second Candidate (and a second Partner contact) for
        // the same real person. Normalize once, up front, so both the
        // lookup and everything stored below use the same canonical form.
        $data['candidate_email'] = trim(mb_strtolower($data['candidate_email']));

        $companyId = (int) ($data['company_id'] ?? $actor->default_company_id);
        if (
            (int) $actor->default_company_id !== $companyId
            && ! $actor->allowedCompanies()->whereKey($companyId)->exists()
        ) {
            throw new RuntimeException('The applicant import company is outside the user’s allowed companies.');
        }

        $job = JobPosition::query()
            ->whereKey($data['job_id'])
            ->where('company_id', $companyId)
            ->firstOrFail();
        $stageId = $data['stage_id'] ?? Stage::query()
            ->where(function ($query) use ($companyId): void {
                $query->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->value('id');
        if ($stageId && ! Stage::query()->whereKey($stageId)->where(function ($query) use ($companyId): void {
            $query->whereNull('company_id')->orWhere('company_id', $companyId);
        })->exists()) {
            throw new RuntimeException('The applicant stage is outside the import company.');
        }

        return DB::transaction(function () use ($actor, $companyId, $data, $job, $stageId, $source): Applicant {
            $existing = Applicant::query()
                ->where('company_id', $companyId)
                ->where('external_application_id', $data['external_application_id'])
                ->first();
            $candidate = $existing?->candidate ?? Candidate::query()
                ->where('company_id', $companyId)
                ->where('email_from', $data['candidate_email'])
                ->first() ?? new Candidate;
            $candidate->fill(array_filter([
                'company_id'       => $companyId,
                'name'             => $data['candidate_name'],
                'email_from'       => $data['candidate_email'],
                'phone'            => $data['candidate_phone'] ?? null,
                'resume_path'      => $data['resume_path'] ?? null,
                'portfolio_url'    => $data['portfolio_url'] ?? null,
                'source_reference' => $data['source_details'],
                'is_active'        => true,
            ], fn ($value): bool => $value !== null));
            $candidate->save();

            $provenance = [
                'adapter'                => $source,
                'external_application_id'=> $data['external_application_id'],
                'source_details'         => $data['source_details'],
                'received_at'            => now()->toIso8601String(),
            ];
            $application = $existing ?? new Applicant;
            $application->fill([
                'candidate_id'           => $candidate->id,
                'company_id'             => $companyId,
                'job_id'                 => $job->id,
                'department_id'          => $job->department_id,
                'stage_id'               => $stageId,
                'source_id'              => $data['source_id'] ?? null,
                'medium_id'              => $data['medium_id'] ?? null,
                'external_application_id'=> $data['external_application_id'],
                'source_details'         => $data['source_details'],
                'creator_id'             => $actor->id,
                'create_date'            => $application->create_date ?? now()->toDateString(),
                'applicant_properties'   => [
                    ...(array) $application->applicant_properties,
                    'source_provenance' => $provenance,
                    'source_metadata'   => Arr::only($data, ['source_id', 'medium_id']),
                ],
                'is_active'              => true,
            ]);
            $application->save();

            return $application->fresh(['candidate', 'job', 'stage']);
        });
    }
}
