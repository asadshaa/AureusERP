<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Database\Factories\AccountFactory;
use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Models\AccountDetail;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class Account extends Model
{
    use HasFactory;

    protected $table = 'accounts_accounts';

    protected $fillable = [
        'currency_id',
        'creator_id',
        'parent_id',
        'account_type',
        'name',
        'code',
        'note',
        'deprecated',
        'reconcile',
        'non_trade',
        'is_group',
        'source_classification_path',
        'import_batch_id',
    ];

    protected $casts = [
        'deprecated'   => 'boolean',
        'reconcile'    => 'boolean',
        'non_trade'    => 'boolean',
        'is_group'     => 'boolean',
        'account_type' => AccountType::class,
    ];

    /**
     * Postable (leaf) accounts only — journal lines may not use group nodes.
     */
    public function scopePostable($query)
    {
        return $query->where('is_group', false);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function getDescendantIds(array $visited = []): array
    {
        $ids = [];
        $visited[] = $this->id;

        foreach ($this->children as $child) {
            if (in_array($child->id, $visited, true)) {
                continue;
            }

            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getDescendantIds($visited));
        }

        return array_values(array_unique($ids));
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'accounts_account_taxes', 'account_id', 'tax_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'accounts_account_account_tags', 'account_id', 'account_tag_id');
    }

    public function journals(): BelongsToMany
    {
        return $this->belongsToMany(Journal::class, 'accounts_account_journals', 'account_id', 'journal_id');
    }

    public function moveLines(): HasMany
    {
        return $this->hasMany(MoveLine::class, 'account_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'accounts_account_companies', 'account_id', 'company_id');
    }

    public function accountingDetail(): HasOne
    {
        return $this->hasOne(AccountDetail::class);
    }

    public static function getMostFrequentAccountsForPartner(
        int $companyId,
        int $partnerId,
        string $moveType,
        bool $filterNeverUsedAccounts = false,
        ?int $limit = null
    ) {
        $minDate = now()->subYears(2)->toDateString();

        // There is no `internal_group` column on accounts_accounts; the group is
        // expressed through account_type, so narrow on the types that make up
        // the income/expense groups instead.
        $group = null;

        if (in_array($moveType, (new Move)->getInboundTypes(true))) {
            $group = array_keys(AccountType::income());
        } elseif (in_array($moveType, (new Move)->getOutboundTypes(true))) {
            $group = array_keys(AccountType::expenses());
        }

        $query = DB::table('accounts_account_move_lines')
            ->select('accounts_account_move_lines.account_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'accounts_account_move_lines.account_id')
            ->where('accounts_account_move_lines.company_id', $companyId)
            ->where('accounts_account_move_lines.partner_id', $partnerId)
            ->where('accounts_accounts.deprecated', false)
            ->whereDate('accounts_account_move_lines.date', '>=', $minDate);

        if ($group) {
            $query->whereIn('accounts_accounts.account_type', $group);
        }

        if (! $filterNeverUsedAccounts) {
            $accountsBase = DB::table('accounts_accounts')
                ->select('accounts_accounts.id as account_id')
                ->join('accounts_account_companies', function ($join) use ($companyId) {
                    $join->on('accounts_account_companies.account_id', '=', 'accounts_accounts.id')
                        ->where('accounts_account_companies.company_id', $companyId);
                })
                ->leftJoin('accounts_account_move_lines', function ($j) use ($companyId, $partnerId, $minDate) {
                    $j->on('accounts_account_move_lines.account_id', '=', 'accounts_accounts.id')
                        ->where('accounts_account_move_lines.company_id', $companyId)
                        ->where('accounts_account_move_lines.partner_id', $partnerId)
                        ->whereDate('accounts_account_move_lines.date', '>=', $minDate);
                })
                ->where('accounts_accounts.deprecated', false);

            if ($group) {
                $accountsBase->whereIn('accounts_accounts.account_type', $group);
            }

            $query = $query->unionAll($accountsBase);
        }

        $query = DB::table(DB::raw("({$query->toSql()}) as q"))
            ->mergeBindings($query)
            ->select('q.account_id')
            ->join('accounts_accounts', 'accounts_accounts.id', '=', 'q.account_id')
            ->groupBy('q.account_id')
            ->orderByRaw('COUNT(q.account_id) DESC')
            ->orderBy('accounts_accounts.code', 'DESC');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->pluck('account_id')->toArray();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            $account->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory()
    {
        return AccountFactory::new();
    }
}
