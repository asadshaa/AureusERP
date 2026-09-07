<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Models\Company;

class PerformanceGoal extends Model
{
    protected $table = 'employees_performance_goals';

    protected $fillable = [
        'company_id', 'review_id', 'title', 'description', 'weight', 'target_value',
        'actual_value', 'rating', 'status', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'weight'       => 'decimal:2',
            'target_value' => 'decimal:4',
            'actual_value' => 'decimal:4',
            'rating'       => 'decimal:2',
            'due_date'     => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $goal): void {
            // No Filament UI creates this record today, but the same
            // company_id gap exists as every other model fixed alongside
            // this one — default it so any future creation path doesn't
            // reintroduce the issue.
            $goal->company_id ??= $goal->review?->company_id ?? Auth::user()?->default_company_id;
        });
    }
}
