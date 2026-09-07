<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\ManualAdjustmentService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function approvalFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $currency->update(['active' => true, 'is_iso_fiat' => true]);

    $company = Company::factory()->create([
        'currency_id' => $currency->id,
        'is_active'   => true,
    ]);

    $user = User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    test()->actingAs($user);

    $bankGl = Account::factory()->create([
        'code'         => 'BNK'.uniqid(),
        'name'         => 'Main Bank Account',
        'account_type' => AccountType::ASSET_CASH,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $bankGl->companies()->attach($company->id);

    $offsetGl = Account::factory()->create([
        'code'         => 'EXP'.uniqid(),
        'name'         => 'Office Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $offsetGl->companies()->attach($company->id);

    $journal = Journal::query()->create([
        'company_id'         => $company->id,
        'currency_id'        => $currency->id,
        'default_account_id' => $bankGl->id,
        'name'               => 'Bank Journal '.uniqid(),
        'code'               => 'BNK'.substr(uniqid(), -4),
        'type'               => 'bank',
    ]);

    $statement = BankStatement::query()->create([
        'company_id'              => $company->id,
        'journal_id'              => $journal->id,
        'currency_id'             => $currency->id,
        'company_currency_id'     => $currency->id,
        'bank_gl_account_id'      => $bankGl->id,
        'name'                    => 'Statement '.uniqid(),
        'reference'               => 'STMT-'.uniqid(),
        'date'                    => '2026-09-01',
        'statement_start_date'    => '2026-09-01',
        'statement_end_date'      => '2026-09-01',
        'opening_balance'         => 1000,
        'closing_balance'         => 900,
        'import_status'           => BankImportStatus::Validated,
    ]);

    $line = BankStatementLine::query()->create([
        'company_id'              => $company->id,
        'journal_id'              => $journal->id,
        'statement_id'            => $statement->id,
        'currency_id'             => $currency->id,
        'original_currency_id'    => $currency->id,
        'company_currency_id'     => $currency->id,
        'transaction_date'        => '2026-09-01',
        'value_date'              => '2026-09-01',
        'description'             => 'Office Supplies',
        'debit'                   => '100.00',
        'credit'                  => '0.00',
        'original_debit'          => '100.00',
        'original_credit'         => '0.00',
        'original_signed_amount'  => '-100.00',
        'company_debit'           => '100.00',
        'company_credit'          => '0.00',
        'company_signed_amount'   => '-100.00',
        'exchange_rate'           => '1.000000',
        'running_balance'         => '900.00',
        'import_status'           => BankImportStatus::Validated,
        'amount'                  => '-100.00',
        'amount_currency'         => '-100.00',
        'amount_residual'         => '-100.00',
        'is_reconciled'           => false,
    ]);

    $mapping = BankTransactionMapping::query()->create([
        'company_id'           => $company->id,
        'statement_line_id'    => $line->id,
        'bank_gl_account_id'   => $bankGl->id,
        'offset_account_id'    => $offsetGl->id,
        'original_currency_id' => $currency->id,
        'company_currency_id'  => $company->currency_id,
        'review_status'        => BankReviewStatus::Unmapped,
        'posting_status'       => BankPostingStatus::NotPosted,
    ]);

    $adjustment = ManualAdjustment::query()->create([
        'company_id'        => $company->id,
        'date'              => '2026-09-01',
        'debit_account_id'  => $offsetGl->id,
        'credit_account_id' => $bankGl->id,
        'amount'            => '250.00',
        'description'       => 'Manual Correction',
        'approval_status'   => ManualAdjustmentStatus::Draft,
        'creator_id'        => $user->id,
    ]);

    return compact('company', 'user', 'mapping', 'adjustment');
}

it('allows direct approval when no workflow is configured (default behavior)', function (): void {
    $fixture = approvalFixture();

    $approvedMapping = app(BankMappingService::class)->approve($fixture['mapping'], $fixture['user']);
    expect($approvedMapping->review_status)->toBe(BankReviewStatus::Approved);

    $approvedAdj = app(ManualAdjustmentService::class)->approve($fixture['adjustment'], $fixture['user']);
    expect($approvedAdj->approval_status)->toBe(ManualAdjustmentStatus::Approved);
});

it('enforces ApprovalEngine workflow when configured for bank mappings and manual adjustments', function (): void {
    $fixture = approvalFixture();

    // Create active workflow for bank_transaction_mapping
    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $fixture['company']->id,
        'name'         => 'Bank Mapping Multi-step Approval',
        'request_type' => 'bank_transaction_mapping',
        'is_active'    => true,
    ]);

    // Attempting direct approve without approved request must throw
    expect(fn () => app(BankMappingService::class)->approve($fixture['mapping'], $fixture['user']))
        ->toThrow(RuntimeException::class, 'requires a completed approval workflow');

    // Create approved request
    ApprovalRequest::query()->create([
        'company_id'   => $fixture['company']->id,
        'workflow_id'  => $workflow->id,
        'request_type' => 'bank_transaction_mapping',
        'subject_type' => $fixture['mapping']->getMorphClass(),
        'subject_id'   => $fixture['mapping']->id,
        'status'       => 'approved',
        'requester_id' => $fixture['user']->id,
    ]);

    // Now approve succeeds
    $approvedMapping = app(BankMappingService::class)->approve($fixture['mapping'], $fixture['user']);
    expect($approvedMapping->review_status)->toBe(BankReviewStatus::Approved);

    // Now test manual adjustment workflow
    $adjWorkflow = ApprovalWorkflow::query()->create([
        'company_id'   => $fixture['company']->id,
        'name'         => 'Manual Adjustment Approval',
        'request_type' => 'manual_adjustment',
        'is_active'    => true,
    ]);

    expect(fn () => app(ManualAdjustmentService::class)->approve($fixture['adjustment'], $fixture['user']))
        ->toThrow(RuntimeException::class, 'requires a completed approval workflow');

    ApprovalRequest::query()->create([
        'company_id'   => $fixture['company']->id,
        'workflow_id'  => $adjWorkflow->id,
        'request_type' => 'manual_adjustment',
        'subject_type' => $fixture['adjustment']->getMorphClass(),
        'subject_id'   => $fixture['adjustment']->id,
        'status'       => 'approved',
        'requester_id' => $fixture['user']->id,
    ]);

    $approvedAdj = app(ManualAdjustmentService::class)->approve($fixture['adjustment'], $fixture['user']);
    expect($approvedAdj->approval_status)->toBe(ManualAdjustmentStatus::Approved);
});
