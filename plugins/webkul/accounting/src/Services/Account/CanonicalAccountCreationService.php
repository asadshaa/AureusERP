<?php

namespace Webkul\Accounting\Services\Account;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Models\AccountDetail;
use Webkul\Accounting\Services\Currency\CompanyCurrencyService;
use Webkul\Support\Models\Company;

class CanonicalAccountCreationService
{
    public function __construct(protected CompanyCurrencyService $companyCurrencies) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBankAccount(Company $company, array $data): Account
    {
        $this->validateBankParentAccount($company, $data);
        $this->validateUniqueBankDetails($company, $data);

        if (array_key_exists('active', $data) && ! (bool) $data['active']) {
            throw new RuntimeException('Bank GL accounts must be active.');
        }

        $data['account_type'] = AccountType::ASSET_CASH->value;
        $data['is_group'] = false;
        $data['active'] = true;

        return $this->create($company, $data, [AccountType::ASSET_CASH]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBankParentAccount(Company $company, array $data): Account
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $type = AccountType::tryFrom((string) ($data['account_type'] ?? AccountType::ASSET_CURRENT->value));
        $currencyId = isset($data['currency_id']) ? (int) $data['currency_id'] : null;

        if ($name === '') {
            throw new RuntimeException('The parent account title is required.');
        }
        if (isset($data['company_id']) && (int) $data['company_id'] !== (int) $company->id) {
            throw new RuntimeException('The parent account company does not match the active company.');
        }
        if (! $type || ! in_array($type, $this->bankParentAccountTypes(), true)) {
            throw new RuntimeException('Bank parent accounts must use an Asset or Bank/Cash account type.');
        }
        if ($currencyId && ! $this->companyCurrencies->isTransactionCurrencyEnabled($company, $currencyId)) {
            throw new RuntimeException('The parent account currency is not enabled for this company.');
        }

        return Cache::lock("accounting-account-code:{$company->id}", 10)->block(5, function () use ($company, $data, $code, $name, $type, $currencyId): Account {
            if ($code !== '' && $this->companyAccountCodeExists($company, $code)) {
                throw new RuntimeException("GL code {$code} already exists for this company.");
            }

            return DB::transaction(function () use ($company, $data, $code, $name, $type, $currencyId): Account {
                $account = Account::query()->create([
                    'code'         => $code !== '' ? $code : null,
                    'name'         => $name,
                    'account_type' => $type,
                    'currency_id'  => $currencyId,
                    'note'         => $data['description'] ?? null,
                    'deprecated'   => false,
                    'reconcile'    => false,
                    'is_group'     => true,
                    'creator_id'   => Auth::id(),
                ]);
                $account->companies()->attach($company->id);

                AccountDetail::query()->create([
                    'account_id'       => $account->id,
                    'company_id'       => $company->id,
                    'currency_id'      => $currencyId,
                    'nature'           => $data['nature'] ?? 'Assets',
                    'classification_1' => $data['classification_1'] ?? null,
                    'description'      => $data['description'] ?? null,
                    'created_by'       => Auth::id(),
                ]);

                return $account->fresh(['companies', 'currency', 'accountingDetail']);
            });
        });
    }

    public function bankAccountQuery(Company $company, ?int $currencyId): Builder
    {
        return Account::query()
            ->postable()
            ->where('deprecated', false)
            ->where('account_type', AccountType::ASSET_CASH)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->when($currencyId, function (Builder $query, int $currencyId) use ($company): void {
                $query->where(function (Builder $currencyQuery) use ($company, $currencyId): void {
                    $currencyQuery->where('currency_id', $currencyId);

                    if ($currencyId === (int) $company->currency_id) {
                        $currencyQuery->orWhereNull('currency_id');
                    }
                });
            });
    }

    public function bankParentAccountQuery(Company $company, ?int $currencyId): Builder
    {
        return Account::query()
            ->where('is_group', true)
            ->where('deprecated', false)
            ->whereIn('account_type', array_map(
                static fn (AccountType $type): string => $type->value,
                $this->bankParentAccountTypes(),
            ))
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->when($currencyId, fn (Builder $query, int $currencyId) => $query
                ->where(fn (Builder $currencyQuery) => $currencyQuery
                    ->whereNull('currency_id')
                    ->orWhere('currency_id', $currencyId)));
    }

    public function offsetParentAccountQuery(Company $company, ?int $currencyId): Builder
    {
        return Account::query()
            ->where('is_group', true)
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
            ->when($currencyId, fn (Builder $query, int $currencyId) => $query
                ->where(fn (Builder $currencyQuery) => $currencyQuery
                    ->whereNull('currency_id')
                    ->orWhere('currency_id', $currencyId)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOffsetAccount(Company $company, array $data): Account
    {
        return $this->create($company, $data, [
            AccountType::EXPENSE,
            AccountType::EXPENSE_DEPRECIATION,
            AccountType::EXPENSE_DIRECT_COST,
            AccountType::INCOME,
            AccountType::INCOME_OTHER,
            AccountType::ASSET_CURRENT,
            AccountType::ASSET_NON_CURRENT,
            AccountType::ASSET_PREPAYMENTS,
            AccountType::ASSET_FIXED,
            AccountType::LIABILITY_CURRENT,
            AccountType::LIABILITY_NON_CURRENT,
            AccountType::EQUITY,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, AccountType>  $allowedTypes
     */
    private function create(Company $company, array $data, array $allowedTypes): Account
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $type = AccountType::tryFrom((string) ($data['account_type'] ?? ''));
        $currencyId = isset($data['currency_id']) ? (int) $data['currency_id'] : null;
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($code === '' || $name === '') {
            throw new RuntimeException('GL code and title are required.');
        }
        if (! $type || ! in_array($type, $allowedTypes, true)) {
            throw new RuntimeException('The selected account type is not allowed for this GL account.');
        }
        if (($data['is_group'] ?? false) === true) {
            throw new RuntimeException('Inline GL accounts must be postable, not group accounts.');
        }
        if ($currencyId && ! $this->companyCurrencies->isTransactionCurrencyEnabled($company, $currencyId)) {
            throw new RuntimeException('The account currency is not enabled for this company.');
        }
        if ($parentId && ! $this->offsetParentAccountQuery($company, $currencyId)->whereKey($parentId)->exists()) {
            throw new RuntimeException('The parent account must be an active company-owned group with a compatible currency.');
        }

        return Cache::lock("accounting-account-code:{$company->id}", 10)->block(5, function () use ($company, $data, $code, $name, $type, $currencyId, $parentId): Account {
            if ($this->companyAccountCodeExists($company, $code)) {
                throw new RuntimeException("GL code {$code} already exists for this company.");
            }

            return DB::transaction(function () use ($company, $data, $code, $name, $type, $currencyId, $parentId): Account {
                $account = Account::query()->create([
                    'code'         => $code,
                    'name'         => $name,
                    'account_type' => $type,
                    'currency_id'  => $currencyId,
                    'parent_id'    => $parentId,
                    'note'         => $data['description'] ?? null,
                    'deprecated'   => ! (bool) ($data['active'] ?? true),
                    'reconcile'    => $type->isReconcilable(),
                    'is_group'     => false,
                    'creator_id'   => Auth::id(),
                ]);
                $account->companies()->attach($company->id);

                AccountDetail::query()->create([
                    'account_id'          => $account->id,
                    'company_id'          => $company->id,
                    'currency_id'         => $currencyId,
                    'nature'              => $data['nature'] ?? null,
                    'classification_1'    => $data['classification_1'] ?? null,
                    'classification_2'    => $data['classification_2'] ?? null,
                    'classification_3'    => $data['classification_3'] ?? null,
                    'classification_4'    => $data['classification_4'] ?? null,
                    'classification_5'    => $data['classification_5'] ?? null,
                    'classification_6'    => $data['classification_6'] ?? null,
                    'classification_7'    => $data['classification_7'] ?? null,
                    'bank_name'           => $data['bank_name'] ?? null,
                    'bank_account_number' => $data['bank_account_number'] ?? null,
                    'iban'                => $data['iban'] ?? null,
                    'branch_reference'    => $data['branch_reference'] ?? null,
                    'description'         => $data['description'] ?? null,
                    'created_by'          => Auth::id(),
                ]);

                return $account->fresh(['companies', 'currency', 'accountingDetail']);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateBankParentAccount(Company $company, array $data): void
    {
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if (! $parentId) {
            return;
        }

        if (! Account::query()->whereKey($parentId)->whereHas(
            'companies',
            fn ($query) => $query->where('companies.id', $company->id),
        )->exists()) {
            throw new RuntimeException('The parent account belongs to another company or does not exist.');
        }

        $currencyId = isset($data['currency_id']) ? (int) $data['currency_id'] : null;

        if (! $this->bankParentAccountQuery($company, $currencyId)->whereKey($parentId)->exists()) {
            throw new RuntimeException('The parent account must be an active Asset or Bank/Cash group with a compatible currency.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateUniqueBankDetails(Company $company, array $data): void
    {
        $bankAccountNumber = trim((string) ($data['bank_account_number'] ?? ''));
        $iban = trim((string) ($data['iban'] ?? ''));

        if ($bankAccountNumber !== '' && AccountDetail::query()
            ->where('company_id', $company->id)
            ->where('bank_account_number', $bankAccountNumber)
            ->exists()) {
            throw new RuntimeException('The bank account number already exists for this company.');
        }

        if ($iban !== '' && AccountDetail::query()
            ->where('company_id', $company->id)
            ->where('iban', $iban)
            ->exists()) {
            throw new RuntimeException('The IBAN already exists for this company.');
        }
    }

    private function companyAccountCodeExists(Company $company, string $code): bool
    {
        return Account::query()->where('code', $code)->whereHas(
            'companies',
            fn ($query) => $query->where('companies.id', $company->id),
        )->exists();
    }

    /**
     * @return array<int, AccountType>
     */
    private function bankParentAccountTypes(): array
    {
        return [
            AccountType::ASSET_CURRENT,
            AccountType::ASSET_CASH,
        ];
    }
}
