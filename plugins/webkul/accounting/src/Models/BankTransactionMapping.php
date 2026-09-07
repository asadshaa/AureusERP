<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Database\Factories\BankTransactionMappingFactory;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankTransactionMapping extends Model
{
    use HasFactory;

    protected static function newFactory(): BankTransactionMappingFactory
    {
        return BankTransactionMappingFactory::new();
    }

    protected $table = 'accounting_bank_transaction_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'review_status'  => BankReviewStatus::class,
            'posting_status' => BankPostingStatus::class,
            'confidence'     => 'decimal:4',
            'reviewed_at'    => 'datetime',
            'posted_at'      => 'datetime',
            'exchange_rate'  => 'decimal:15',
            'rate_date'      => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'statement_line_id');
    }

    public function bankGlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_gl_account_id');
    }

    public function offsetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'offset_account_id');
    }

    public function fsTag(): BelongsTo
    {
        return $this->belongsTo(FsTag::class, 'fs_tag_id');
    }

    public function mappingRule(): BelongsTo
    {
        return $this->belongsTo(BankMappingRule::class, 'mapping_rule_id');
    }

    public function transferMatch(): BelongsTo
    {
        return $this->belongsTo(BankTransferMatch::class, 'transfer_match_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }

    public function matchedMove(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'matched_move_id');
    }

    public function originalCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'original_currency_id');
    }

    public function companyCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'company_currency_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $mapping): void {
            $mapping->map_reference ??= 'MAP-'.Str::upper(Str::random(12));
        });
    }
}
