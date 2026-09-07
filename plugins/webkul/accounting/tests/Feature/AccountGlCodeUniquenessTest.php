<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Services\Account\CanonicalAccountCreationService;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function glCodeCompany(): Company
{
    $currency = Currency::query()->where('name', 'PKR')->first() ?? Currency::query()->firstOrFail();

    return Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
}

it('rejects a duplicate GL code within the same company', function () {
    $company = glCodeCompany();
    $service = app(CanonicalAccountCreationService::class);

    $service->createOffsetAccount($company, [
        'code' => 'GL-DUP-1', 'name' => 'First expense account', 'account_type' => AccountType::EXPENSE->value,
    ]);

    expect(fn () => $service->createOffsetAccount($company, [
        'code' => 'GL-DUP-1', 'name' => 'Second expense account', 'account_type' => AccountType::EXPENSE->value,
    ]))->toThrow(RuntimeException::class, 'already exists for this company');
});

it('allows the same GL code across two different companies', function () {
    $companyA = glCodeCompany();
    $companyB = glCodeCompany();
    $service = app(CanonicalAccountCreationService::class);

    $accountA = $service->createOffsetAccount($companyA, [
        'code' => 'GL-SHARED-1', 'name' => 'Company A expense account', 'account_type' => AccountType::EXPENSE->value,
    ]);
    $accountB = $service->createOffsetAccount($companyB, [
        'code' => 'GL-SHARED-1', 'name' => 'Company B expense account', 'account_type' => AccountType::EXPENSE->value,
    ]);

    expect($accountA->id)->not->toBe($accountB->id)
        ->and($accountA->code)->toBe('GL-SHARED-1')
        ->and($accountB->code)->toBe('GL-SHARED-1');
});
