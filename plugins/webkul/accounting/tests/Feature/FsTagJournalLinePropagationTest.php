<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\JournalItem;
use Webkul\Accounting\Services\Bank\BankJournalCreationService;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function fsTagPropagationFixture(): array
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
        'code'         => 'BNK-PROP-'.$company->id,
        'name'         => 'Operating Bank Account',
        'account_type' => AccountType::ASSET_CASH,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $bankGl->companies()->attach($company->id);

    $expenseGl = Account::factory()->create([
        'code'         => 'EXP-FEE-'.$company->id,
        'name'         => 'Bank Service Charges',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $expenseGl->companies()->attach($company->id);

    $incomeGl = Account::factory()->create([
        'code'         => 'INC-INT-'.$company->id,
        'name'         => 'Interest Revenue',
        'account_type' => AccountType::INCOME,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $incomeGl->companies()->attach($company->id);

    $bankJournal = app(BankJournalCreationService::class)->create($company, [
        'currency_id'        => $currency->id,
        'default_account_id' => $bankGl->id,
        'name'               => 'Operating Bank Journal',
        'code'               => 'OPBNK'.$company->id,
    ]);

    return compact('currency', 'company', 'user', 'bankGl', 'expenseGl', 'incomeGl', 'bankJournal');
}

function createTestStatementLine(array $fixture, string $debit, string $credit, string $reference): BankStatementLine
{
    $amount = (float) $debit > 0 ? $debit : $credit;
    $statement = BankStatement::query()->create([
        'company_id'              => $fixture['company']->id,
        'journal_id'              => $fixture['bankJournal']->id,
        'currency_id'             => $fixture['currency']->id,
        'company_currency_id'     => $fixture['currency']->id,
        'bank_gl_account_id'      => $fixture['bankGl']->id,
        'name'                    => 'Test Bank Statement',
        'reference'               => 'STMT-'.$reference,
        'date'                    => '2026-08-30',
        'statement_start_date'    => '2026-08-30',
        'statement_end_date'      => '2026-08-30',
        'opening_balance'         => 10000,
        'total_debits'            => $debit,
        'total_credits'           => $credit,
        'closing_balance'         => (float) $debit > 0 ? 10000 - (float) $debit : 10000 + (float) $credit,
        'balance_start'           => 10000,
        'balance_end'             => (float) $debit > 0 ? 10000 - (float) $debit : 10000 + (float) $credit,
        'balance_end_real'        => (float) $debit > 0 ? 10000 - (float) $debit : 10000 + (float) $credit,
        'company_opening_balance' => 10000,
        'company_total_debits'    => $debit,
        'company_total_credits'   => $credit,
        'company_closing_balance' => (float) $debit > 0 ? 10000 - (float) $debit : 10000 + (float) $credit,
        'conversion_status'       => ConversionStatus::Complete,
        'bank_name'               => 'Test Operating Bank',
        'bank_account_number'     => 'OP-001',
        'account_title'           => 'Operating Title',
        'original_filename'       => 'statement.csv',
        'file_hash'               => hash('sha256', $reference.$fixture['company']->id.now()->toIso8601String()),
        'parser'                  => 'test',
        'import_status'           => BankImportStatus::Validated,
    ]);

    return BankStatementLine::query()->create([
        'journal_id'              => $fixture['bankJournal']->id,
        'company_id'              => $fixture['company']->id,
        'statement_id'            => $statement->id,
        'currency_id'             => $fixture['currency']->id,
        'original_currency_id'    => $fixture['currency']->id,
        'company_currency_id'     => $fixture['currency']->id,
        'transaction_date'        => '2026-08-30',
        'value_date'              => '2026-08-30',
        'description'             => 'Test transaction '.$reference,
        'reference'               => $reference,
        'debit'                   => $debit,
        'credit'                  => $credit,
        'original_debit'          => $debit,
        'original_credit'         => $credit,
        'original_signed_amount'  => (float) $debit > 0 ? "-{$debit}" : $credit,
        'company_debit'           => $debit,
        'company_credit'          => $credit,
        'company_signed_amount'   => (float) $debit > 0 ? "-{$debit}" : $credit,
        'amount'                  => $amount,
        'amount_currency'         => $amount,
        'amount_residual'         => $amount,
        'exchange_rate'           => 1,
        'rate_date'               => '2026-08-30',
        'rate_source'             => 'identity',
        'rate_type'               => 'transaction',
        'conversion_status'       => ConversionStatus::Complete,
        'transaction_fingerprint' => hash('sha256', $reference.$amount.$fixture['company']->id),
        'import_status'           => BankImportStatus::Validated,
    ]);
}

it('propagates fs_tag_id to the offset journal move line when approving and posting a bank debit line', function (): void {
    $fixture = fsTagPropagationFixture();

    $feeTag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS-FEE-'.$fixture['company']->id,
        'name'               => 'Bank Charge Tag',
        'account_id'         => $fixture['expenseGl']->id,
        'cash_flow_category' => 'Operating',
        'tax_treatment'      => 'Exempt',
        'is_active'          => true,
    ]);

    $statementLine = createTestStatementLine($fixture, debit: '150.0000', credit: '0.0000', reference: 'FEE-001');

    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $statementLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $feeTag->id,
        'match_type'          => 'fs_tag',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    // 1. Approve mapping
    $approved = app(BankMappingService::class)->approve($mapping, $fixture['user'], false);
    expect($approved->offset_account_id)->toBe($fixture['expenseGl']->id)
        ->and($approved->cash_flow_category)->toBe('Operating')
        ->and($approved->tax_treatment)->toBe('Exempt')
        ->and($approved->review_status)->toBe(BankReviewStatus::Approved);

    // 2. Generate draft journal move
    $draftMove = app(BankJournalService::class)->createDraft($approved);
    expect($draftMove->state)->toBe(MoveState::DRAFT)
        ->and($draftMove->cash_flow_category)->toBe('Operating')
        ->and($draftMove->tax_treatment)->toBe('Exempt')
        ->and($draftMove->lines)->toHaveCount(2);

    $expenseLine = $draftMove->lines()->where('account_id', $fixture['expenseGl']->id)->firstOrFail();
    $bankLine = $draftMove->lines()->where('account_id', $fixture['bankGl']->id)->firstOrFail();

    // Verify fs_tag_id was correctly written to the offset expense line
    expect($expenseLine->fs_tag_id)->toBe($feeTag->id)
        ->and((float) $expenseLine->debit)->toBe(150.0)
        ->and($bankLine->fs_tag_id)->toBeNull()
        ->and((float) $bankLine->credit)->toBe(150.0);

    // Verify JournalItem Eloquent relationship resolves the FS Tag
    $journalItem = JournalItem::query()->findOrFail($expenseLine->id);
    expect($journalItem->fsTag)->not->toBeNull()
        ->and($journalItem->fsTag->code)->toBe($feeTag->code)
        ->and($journalItem->fsTag->name)->toBe('Bank Charge Tag');

    // 3. Post the journal move
    $postedMove = app(BankJournalService::class)->post($approved->fresh(), $fixture['user']);
    expect($postedMove->state)->toBe(MoveState::POSTED);

    $postedExpenseLine = $postedMove->lines()->where('account_id', $fixture['expenseGl']->id)->firstOrFail();
    expect($postedExpenseLine->fs_tag_id)->toBe($feeTag->id)
        ->and($postedExpenseLine->parent_state)->toBe(MoveState::POSTED);
});

it('propagates fs_tag_id to the offset credit line for incoming bank deposits', function (): void {
    $fixture = fsTagPropagationFixture();

    $interestTag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS-INT-'.$fixture['company']->id,
        'name'               => 'Interest Income Tag',
        'account_id'         => $fixture['incomeGl']->id,
        'cash_flow_category' => 'Operating',
        'tax_treatment'      => 'Standard',
        'is_active'          => true,
    ]);

    $statementLine = createTestStatementLine($fixture, debit: '0.0000', credit: '500.0000', reference: 'INT-001');

    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $statementLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $interestTag->id,
        'match_type'          => 'fs_tag',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    $approved = app(BankMappingService::class)->approve($mapping, $fixture['user'], false);
    $draftMove = app(BankJournalService::class)->createDraft($approved);

    $incomeLine = $draftMove->lines()->where('account_id', $fixture['incomeGl']->id)->firstOrFail();
    $bankLine = $draftMove->lines()->where('account_id', $fixture['bankGl']->id)->firstOrFail();

    expect($incomeLine->fs_tag_id)->toBe($interestTag->id)
        ->and((float) $incomeLine->credit)->toBe(500.0)
        ->and($bankLine->fs_tag_id)->toBeNull()
        ->and((float) $bankLine->debit)->toBe(500.0);

    $postedMove = app(BankJournalService::class)->post($approved->fresh(), $fixture['user']);
    expect($postedMove->state)->toBe(MoveState::POSTED);

    $postedIncomeLine = $postedMove->lines()->where('account_id', $fixture['incomeGl']->id)->firstOrFail();
    expect($postedIncomeLine->fs_tag_id)->toBe($interestTag->id);
});
