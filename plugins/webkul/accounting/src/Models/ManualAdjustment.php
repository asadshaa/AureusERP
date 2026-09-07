<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Database\Factories\ManualAdjustmentFactory;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ManualAdjustment extends Model
{
    use HasFactory;

    protected static function newFactory(): ManualAdjustmentFactory
    {
        return ManualAdjustmentFactory::new();
    }

    protected $table = 'accounting_manual_adjustments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date'            => 'date',
            'amount'          => 'decimal:4',
            'approval_status' => ManualAdjustmentStatus::class,
            'reviewed_at'     => 'datetime',
            'posted_at'       => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $adjustment->creator_id ??= Auth::id();
            $adjustment->adjustment_reference ??= 'ADJ-'.Str::upper(Str::random(12));
        });
    }
}
