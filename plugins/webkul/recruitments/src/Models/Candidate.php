<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Services\CandidateConversionService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class Candidate extends Model
{
    use HasChatter, HasLogActivity, SoftDeletes;

    public const ACTIVITY_PLAN_PLUGIN = 'recruitments';

    protected $table = 'recruitments_candidates';

    protected $fillable = [
        'message_bounced',
        'company_id',
        'partner_id',
        'degree_id',
        'manager_id',
        'employee_id',
        'creator_id',
        'email_cc',
        'name',
        'email_from',
        'priority',
        'phone',
        'linkedin_profile',
        'resume_path',
        'portfolio_url',
        'source_reference',
        'availability_date',
        'candidate_properties',
        'is_active',
    ];

    protected $casts = [
        'candidate_properties' => 'array',
        'is_active'            => 'boolean',
    ];

    public function getLogAttributeLabels(): array
    {
        return [
            'company.name'      => __('recruitments::models/candidate.log-attributes.company'),
            'partner.name'      => __('recruitments::models/candidate.log-attributes.contact'),
            'degree.name'       => __('recruitments::models/candidate.log-attributes.degree'),
            'user.name'         => __('recruitments::models/candidate.log-attributes.manager'),
            'employee.name'     => __('recruitments::models/candidate.log-attributes.employee'),
            'creator.name'      => __('recruitments::models/candidate.log-attributes.creator'),
            'phone_sanitized'   => __('recruitments::models/candidate.log-attributes.phone'),
            'email_normalized'  => __('recruitments::models/candidate.log-attributes.email'),
            'email_cc'          => __('recruitments::models/candidate.log-attributes.email_cc'),
            'name'              => __('recruitments::models/candidate.log-attributes.name'),
            'email_from'        => __('recruitments::models/candidate.log-attributes.email_from'),
            'phone'             => __('recruitments::models/candidate.log-attributes.phone_raw'),
            'linkedin_profile'  => __('recruitments::models/candidate.log-attributes.linkedin_profile'),
            'availability_date' => __('recruitments::models/candidate.log-attributes.availability_date'),
            'is_active'         => __('recruitments::models/candidate.log-attributes.is_active'),
        ];
    }

    public function getModelTitle(): string
    {
        return __('recruitments::models/candidate.title');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function degree()
    {
        return $this->belongsTo(Degree::class, 'degree_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function categories()
    {
        return $this->belongsToMany(ApplicantCategory::class, 'recruitments_candidate_applicant_categories', 'candidate_id', 'category_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CandidateSkill::class, 'candidate_id');
    }

    public function createEmployee(): ?Employee
    {
        // Already converted: return the existing employee directly rather
        // than re-deriving "the" application below — which application
        // happens to look right no longer matters once conversion has
        // already happened (CandidateConversionService::convert() is
        // idempotent on employee_id anyway, but there's no reason to run
        // the ambiguity check below for a no-op).
        if ($this->employee_id) {
            return Employee::find($this->employee_id);
        }

        $applications = Applicant::query()->where('candidate_id', $this->id)->get();

        if ($applications->isEmpty()) {
            return null;
        }

        // A candidate can legitimately have more than one application (e.g.
        // applying to several postings). Picking "whichever was created
        // most recently" silently converted the wrong one whenever that
        // wasn't the application actually being hired. Require an
        // unambiguous signal instead: convert outright when there's only
        // one application, or when exactly one carries an accepted offer —
        // the real, recruiter-set marker for "this is the one being hired."
        // Anything else is a genuine ambiguity; surface it instead of
        // guessing.
        $accepted = $applications->where('offer_status', 'accepted');

        $application = match (true) {
            $applications->count() === 1 => $applications->first(),
            $accepted->count() === 1     => $accepted->first(),
            $accepted->count() > 1       => throw new RuntimeException(
                'This candidate has more than one application with an accepted offer — resolve which one is actually being hired before converting.'
            ),
            default => throw new RuntimeException(
                'This candidate has multiple applications and none has an accepted offer yet — accept the offer on the correct application before converting to an employee.'
            ),
        };

        return app(CandidateConversionService::class)->convert($application);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($candidate) {
            $authUser = Auth::user();

            $candidate->creator_id ??= $authUser->id;

            $candidate->company_id ??= $authUser?->default_company_id;
        });

        static::saved(function (self $candidate) {
            if (! $candidate->partner_id) {
                $candidate->handlePartnerCreation($candidate);
            } else {
                $candidate->handlePartnerUpdation($candidate);
            }
        });
    }

    private function handlePartnerCreation(self $candidate)
    {
        $partner = $candidate->partner()->create([
            'creator_id' => Auth::user()->id ?? $candidate->id,
            'sub_type'   => 'partner',
            'company_id' => $candidate->company_id,
            'phone'      => $candidate->phone,
            'email'      => $candidate->email_from,
            'name'       => $candidate->name,
        ]);

        $candidate->partner_id = $partner->id;
        $candidate->save();
    }

    private function handlePartnerUpdation(self $candidate)
    {
        $partner = Partner::updateOrCreate(
            ['id' => $candidate->partner_id],
            [
                'creator_id' => Auth::user()->id ?? $candidate->id,
                'sub_type'   => 'partner',
                'company_id' => $candidate->company_id,
                'phone'      => $candidate->phone,
                'email'      => $candidate->email_from,
                'name'       => $candidate->name,
            ]
        );

        if ($candidate->partner_id !== $partner->id) {
            $candidate->partner_id = $partner->id;
            $candidate->save();
        }
    }
}
