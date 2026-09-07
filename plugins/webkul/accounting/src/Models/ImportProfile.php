<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Accounting\Database\Factories\ImportProfileFactory;
use Webkul\Accounting\Models\Concerns\AuditsConfiguration;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ImportProfile extends Model
{
    use AuditsConfiguration, HasFactory;

    protected static function newFactory(): ImportProfileFactory
    {
        return ImportProfileFactory::new();
    }

    protected $table = 'accounting_import_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stop_rule'    => 'array',
            'is_active'    => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_profile_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ImportProfileMapping::class, 'profile_id')->orderBy('position');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BusinessRule::class, 'profile_id')->orderBy('priority');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ImportRun::class, 'profile_id');
    }
}
