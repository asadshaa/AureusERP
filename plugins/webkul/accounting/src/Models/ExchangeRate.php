<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Webkul\Accounting\Database\Factories\ExchangeRateFactory;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class ExchangeRate extends Model
{
    use HasFactory;

    protected static function newFactory(): ExchangeRateFactory
    {
        return ExchangeRateFactory::new();
    }

    protected $table = 'accounting_exchange_rates';

    protected $fillable = [
        'company_id',
        'source_currency_id',
        'target_currency_id',
        'effective_date',
        'rate',
        'rate_type',
        'source',
        'approval_status',
        'source_reference',
        'provider',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'effective_date'  => 'date',
            'rate'            => 'decimal:15',
            'rate_type'       => ExchangeRateType::class,
            'source'          => ExchangeRateSource::class,
            'approval_status' => ExchangeRateApprovalStatus::class,
            'approved_at'     => 'datetime',
            'audit_metadata'  => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'source_currency_id');
    }

    public function targetCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'target_currency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected static function booted(): void
    {
        static::creating(function (self $rate): void {
            $rate->created_by ??= Auth::id();
        });

        static::saved(fn (self $rate) => self::invalidateCompanyCache((int) $rate->company_id));
        static::deleted(fn (self $rate) => self::invalidateCompanyCache((int) $rate->company_id));
    }

    public static function invalidateCompanyCache(int $companyId): void
    {
        Cache::put("accounting.exchange-rate-version.{$companyId}", Str::uuid()->toString(), now()->addYears(10));
    }
}
