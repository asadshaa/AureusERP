<?php

/**
 * Regression coverage for the "unpaid invoice reports itself as Paid" bug.
 *
 * MoveLine::computeAmountResidual() short-circuits and forces amount_residual
 * to 0 when the line's account is not reconcilable. A receivable account
 * created without `reconcile` therefore makes every invoice posted against it
 * look fully settled the moment it is posted, and leaves nothing for bank
 * reconciliation to match. Receivable/payable/cash accounts must always be
 * created with the flag set.
 */

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Services\Coa\CoaHeaderDetector;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaSheetParser;
use Webkul\Accounting\Services\Coa\CoaSheetReader;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

it('marks receivable, payable, cash and credit-card types as reconcilable and nothing else', function () {
    $reconcilable = [
        AccountType::ASSET_RECEIVABLE,
        AccountType::LIABILITY_PAYABLE,
        AccountType::ASSET_CASH,
        AccountType::LIABILITY_CREDIT_CARD,
    ];

    foreach ($reconcilable as $type) {
        expect($type->isReconcilable())->toBeTrue("{$type->value} should be reconcilable");
    }

    foreach (AccountType::cases() as $type) {
        if (in_array($type, $reconcilable, true)) {
            continue;
        }

        expect($type->isReconcilable())->toBeFalse("{$type->value} should not be reconcilable");
    }
});

it('creates reconcilable receivable and payable accounts when importing a chart of accounts', function () {
    $company = Company::factory()->create([
        'currency_id' => Currency::query()->orderBy('id')->value('id'),
    ]);

    Journal::factory()->create([
        'company_id'  => $company->id,
        'currency_id' => $company->currency_id,
        'type'        => JournalType::GENERAL,
    ]);

    $reader = new CoaSheetReader;
    $raw = $reader->read(base_path('Chart_of_Accounts_Trial_Balance_Test.csv'));
    $map = (new CoaHeaderDetector)->detect($raw);
    $rows = (new CoaSheetParser)->parse($raw, $map);

    app(CoaImportService::class)->import(
        rows: $rows,
        company: $company,
        mode: 'accounts_only',
        currencyId: $company->currency_id,
        filename: 'Chart_of_Accounts_Trial_Balance_Test.csv',
    );

    $imported = Account::query()
        ->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))
        ->where('is_group', false)
        ->get();

    expect($imported)->not->toBeEmpty();

    foreach ($imported as $account) {
        if ($account->account_type->isReconcilable()) {
            expect((bool) $account->reconcile)->toBeTrue(
                "Imported {$account->account_type->value} account {$account->code} must be reconcilable"
            );
        } else {
            expect((bool) $account->reconcile)->toBeFalse(
                "Imported {$account->account_type->value} account {$account->code} must not be reconcilable"
            );
        }
    }
});
