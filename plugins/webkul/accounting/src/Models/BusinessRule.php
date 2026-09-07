<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Accounting\Database\Factories\BusinessRuleFactory;
use Webkul\Accounting\Models\Concerns\AuditsConfiguration;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class BusinessRule extends Model
{
    use AuditsConfiguration, HasFactory;

    protected static function newFactory(): BusinessRuleFactory
    {
        return BusinessRuleFactory::new();
    }

    protected $table = 'accounting_business_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conditions'      => 'array',
            'actions'         => 'array',
            'effective_from'  => 'date',
            'effective_until' => 'date',
            'stop_processing' => 'boolean',
            'is_active'       => 'boolean',
        ];
    }

    public function scopeEffective(Builder $query, int $companyId, string $entityType, ?string $date = null): Builder
    {
        $effectiveDate = $date ?? now()->toDateString();

        return $query
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->where(fn (Builder $builder) => $builder->whereNull('effective_from')->orWhereDate('effective_from', '<=', $effectiveDate))
            ->where(fn (Builder $builder) => $builder->whereNull('effective_until')->orWhereDate('effective_until', '>=', $effectiveDate))
            ->orderBy('priority')
            ->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class, 'profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
