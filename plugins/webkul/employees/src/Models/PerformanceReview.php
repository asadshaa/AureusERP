<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Models\Company;

class PerformanceReview extends Model
{
    protected $table = 'employees_performance_reviews';

    protected $fillable = [
        'company_id', 'cycle_id', 'employee_id', 'reviewer_id', 'self_rating', 'manager_rating',
        'competency_ratings', 'self_comments', 'manager_comments', 'improvement_plan',
        'promotion_recommendation', 'status', 'submitted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'self_rating'       => 'decimal:2',
            'manager_rating'    => 'decimal:2',
            'competency_ratings'=> 'array',
            'submitted_at'      => 'datetime',
            'completed_at'      => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class, 'review_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $review): void {
            // PerformanceService::launch() always passes company_id
            // explicitly, so this only fires as a safety net for any other
            // creation path (Filament's inherited Create action, tinker,
            // future code) that doesn't.
            $review->company_id ??= Auth::user()?->default_company_id;
        });
    }
}
