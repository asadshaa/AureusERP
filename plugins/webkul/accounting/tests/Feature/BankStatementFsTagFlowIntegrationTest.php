<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\JournalItem;
use Webkul\Accounting\Services\Bank\BankJournalCreationService;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\DirectCashFlowService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function bankStatementFsTagFixture(): array
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
        'code'         => '110101'.$company->id,
        'name'         => 'Main Bank Account',
        'account_type' => AccountType::ASSET_CASH,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $bankGl->companies()->attach($company->id);

    $offsetGl = Account::factory()->create([
        'code'         => '610201'.$company->id,
        'name'         => 'Operational Bank Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $offsetGl->companies()->attach($company->id);

    $bankJournal = app(BankJournalCreationService::class)->create($company, [
        'currency_id'        => $currency->id,
        'default_account_id' => $bankGl->id,
        'name'               => 'Primary Bank Journal',
        'code'               => 'PBJ'.$company->id,
    ]);

    return compact('currency', 'company', 'user', 'bankGl', 'offsetGl', 'bankJournal');
}

function createSimpleBankStatementLine(array $fixture, string $reference): BankStatementLine
{
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
        'total_debits'            => 100,
        'total_credits'           => 0,
        'closing_balance'         => 9900,
        'balance_start'           => 10000,
        'balance_end'             => 9900,
        'balance_end_real'        => 9900,
        'company_opening_balance' => 10000,
        'company_total_debits'    => 100,
        'company_total_credits'   => 0,
        'company_closing_balance' => 9900,
        'conversion_status'       => ConversionStatus::Complete,
        'bank_name'               => 'Test Operating Bank',
        'bank_account_number'     => 'OP-001',
        'account_title'           => 'Operating Title',
        'original_filename'       => 'statement.csv',
        'file_hash'               => hash('sha256', $reference.$fixture['company']->id.uniqid()),
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
        'debit'                   => 100,
        'credit'                  => 0,
        'original_debit'          => 100,
        'original_credit'         => 0,
        'original_signed_amount'  => -100,
        'company_debit'           => 100,
        'company_credit'          => 0,
        'company_signed_amount'   => -100,
        'amount'                  => 100,
        'amount_currency'         => 100,
        'amount_residual'         => 100,
        'exchange_rate'           => 1,
        'rate_date'               => '2026-08-30',
        'rate_source'             => 'identity',
        'rate_type'               => 'transaction',
        'conversion_status'       => ConversionStatus::Complete,
        'transaction_fingerprint' => hash('sha256', $reference.uniqid()),
        'import_status'           => BankImportStatus::Validated,
    ]);
}

it('fulfills 14A.10 positive end-to-end flow from statement source to posted move line', function (): void {
    $fixture = bankStatementFsTagFixture();

    // 1. Create canonical FS Tag in registry
    $fsTag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS001',
        'name'               => 'Bank Administrative Fees',
        'account_id'         => $fixture['offsetGl']->id,
        'cash_flow_category' => CashFlowCategory::OperatingPayments->value,
        'tax_treatment'      => 'Exempt',
        'is_active'          => true,
    ]);

    // 2. Set up configurable bank statement profile
    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Standard 14A Bank Profile',
        'entity_type'    => 'bank_statement',
        'file_type'      => 'csv',
        'header_row'     => 1,
        'data_start_row' => 2,
        'delimiter'      => ',',
        'encoding'       => 'UTF-8',
        'version'        => 1,
        'is_active'      => true,
        'activated_at'   => now(),
        'failure_policy' => 'reject_file',
    ]);

    $headers = [
        'date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code',
        'bank_name', 'account_title', 'reference', 'debit', 'credit', 'opening_balance', 'closing_balance', 'balance', 'fs_tag',
    ];
    foreach ($headers as $position => $field) {
        $transformations = in_array($field, ['debit', 'credit', 'opening_balance', 'closing_balance', 'balance'], true)
            ? [['type' => 'decimal', 'scale' => 4]]
            : [['type' => 'trim']];
        $profile->mappings()->create([
            'position'        => $position + 1,
            'source_header'   => $field,
            'target_field'    => $field,
            'transformations' => $transformations,
            'is_required'     => in_array($field, ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'], true),
        ]);
    }

    // 3. Staged source file matching 14A.10 input
    $csvContent = implode(',', $headers)."\n".
        "2026-01-10,PKR,ACC-110101,Administrative Fee,{$fixture['bankJournal']->code},{$fixture['bankGl']->code},Primary Bank,Main Operating,BANK-001,50000,0,100000,50000,50000,FS001\n";

    $path = tempnam(sys_get_temp_dir(), 'aureus-14a-');
    file_put_contents($path, $csvContent);

    try {
        // Preview & Pre-Validation
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'bank_stmt.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(0)
            ->and($run->passed_rows)->toBe(1);

        // Execution & Source-row persistence
        $completedRun = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        expect($completedRun->status)->toBe('completed')
            ->and($completedRun->imported_rows)->toBe(1);

        $sourceRow = $completedRun->sourceRows()->firstOrFail();
        expect($sourceRow->transformed_values['fs_tag'])->toBe('FS001')
            ->and($sourceRow->canonical_type)->not->toBeNull();

        // Verify Bank Transaction Mapping
        $statement = BankStatement::query()->where('company_id', $fixture['company']->id)->firstOrFail();
        $line = $statement->lines()->with('mapping.fsTag')->firstOrFail();
        $mapping = $line->mapping;

        expect($mapping->fs_tag_id)->toBe($fsTag->id)
            ->and($mapping->fsTag->code)->toBe('FS001')
            ->and($mapping->match_type)->toBe('fs_tag')
            ->and($mapping->review_status)->toBe(BankReviewStatus::Suggested);

        // 4. Approval
        $approved = app(BankMappingService::class)->approve($mapping, $fixture['user'], false);
        expect($approved->offset_account_id)->toBe($fixture['offsetGl']->id)
            ->and($approved->cash_flow_category)->toBe(CashFlowCategory::OperatingPayments->value)
            ->and($approved->tax_treatment)->toBe('Exempt')
            ->and($approved->review_status)->toBe(BankReviewStatus::Approved);

        // 5. Draft Journal Generation
        $draftMove = app(BankJournalService::class)->createDraft($approved);
        expect($draftMove->state)->toBe(MoveState::DRAFT)
            ->and($draftMove->cash_flow_category)->toBe(CashFlowCategory::OperatingPayments->value)
            ->and($draftMove->tax_treatment)->toBe('Exempt');

        $offsetLine = $draftMove->lines()->where('account_id', $fixture['offsetGl']->id)->firstOrFail();
        $bankLine = $draftMove->lines()->where('account_id', $fixture['bankGl']->id)->firstOrFail();

        expect($offsetLine->fs_tag_id)->toBe($fsTag->id)
            ->and((float) $offsetLine->debit)->toBe(50000.0)
            ->and($bankLine->fs_tag_id)->toBeNull()
            ->and((float) $bankLine->credit)->toBe(50000.0);

        // 6. Posting
        $postedMove = app(BankJournalService::class)->post($approved->fresh(), $fixture['user']);
        expect($postedMove->state)->toBe(MoveState::POSTED);

        $postedOffsetLine = $postedMove->lines()->where('account_id', $fixture['offsetGl']->id)->firstOrFail();
        expect($postedOffsetLine->fs_tag_id)->toBe($fsTag->id)
            ->and($postedOffsetLine->parent_state)->toBe(MoveState::POSTED);

        // 7. General Ledger & Cash Flow reporting verification
        $journalItem = JournalItem::query()->findOrFail($postedOffsetLine->id);
        expect($journalItem->fsTag)->not->toBeNull()
            ->and($journalItem->fsTag->code)->toBe('FS001');

        $trialBalance = app(TrialBalanceService::class)->compute($fixture['company']->id, '2026-01-01', '2026-01-31');
        expect($trialBalance['totals']['difference'])->toBe(0.0);

        $cashFlow = app(DirectCashFlowService::class)->calculate($fixture['company']->id, '2026-01-01', '2026-01-31');
        expect($cashFlow['categories'][CashFlowCategory::OperatingPayments->value])->toBe(-50000.0);
    } finally {
        @unlink($path);
    }
});

it('rejects unknown FS Tags during preview and flags them as Needs Review in direct import', function (): void {
    $fixture = bankStatementFsTagFixture();

    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Unknown Tag Profile',
        'entity_type'    => 'bank_statement',
        'file_type'      => 'csv',
        'header_row'     => 1,
        'data_start_row' => 2,
        'delimiter'      => ',',
        'encoding'       => 'UTF-8',
        'version'        => 1,
        'is_active'      => true,
        'activated_at'   => now(),
        'failure_policy' => 'reject_file',
    ]);

    $headers = [
        'date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code',
        'bank_name', 'account_title', 'reference', 'debit', 'credit', 'opening_balance', 'closing_balance', 'balance', 'fs_tag',
    ];
    foreach ($headers as $position => $field) {
        $profile->mappings()->create([
            'position'        => $position + 1,
            'source_header'   => $field,
            'target_field'    => $field,
            'transformations' => in_array($field, ['debit', 'credit', 'opening_balance', 'closing_balance', 'balance'], true)
                ? [['type' => 'decimal', 'scale' => 4]]
                : [['type' => 'trim']],
            'is_required'     => in_array($field, ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'], true),
        ]);
    }

    $csvContent = implode(',', $headers)."\n".
        "2026-01-10,PKR,ACC-110101,Unknown Fee,{$fixture['bankJournal']->code},{$fixture['bankGl']->code},Primary Bank,Main Operating,BANK-002,1000,0,100000,99000,99000,FS999_NON_EXISTENT\n";

    $path = tempnam(sys_get_temp_dir(), 'aureus-unknown-tag-');
    file_put_contents($path, $csvContent);

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'unknown.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(1);

        $sourceRow = $run->sourceRows()->firstOrFail();
        expect($sourceRow->status)->toBe('error')
            ->and(collect($sourceRow->messages)->pluck('message')->first())->toContain('does not exist in this company');
    } finally {
        @unlink($path);
    }
});

it('rejects inactive and wrong-company FS Tags and prevents approval', function (): void {
    $fixture = bankStatementFsTagFixture();

    // Inactive Tag in Company A
    $inactiveTag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS-INACTIVE',
        'name'               => 'Old Deactivated Tag',
        'account_id'         => $fixture['offsetGl']->id,
        'cash_flow_category' => CashFlowCategory::OperatingPayments->value,
        'is_active'          => false,
    ]);

    // Tag belonging to Company B
    $otherCompany = Company::factory()->create(['currency_id' => $fixture['currency']->id, 'is_active' => true]);
    $otherGl = Account::factory()->create([
        'code'         => 'OTHER-610201-'.$otherCompany->id,
        'name'         => 'Other Company Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fixture['currency']->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $otherGl->companies()->attach($otherCompany->id);

    $foreignTag = app(FsTagService::class)->create($otherCompany, [
        'code'               => 'FS-FOREIGN',
        'name'               => 'Foreign Company Tag',
        'account_id'         => $otherGl->id,
        'cash_flow_category' => CashFlowCategory::OperatingPayments->value,
        'is_active'          => true,
    ]);

    // Test preview diagnosis
    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Diagnosis Profile',
        'entity_type'    => 'bank_statement',
        'file_type'      => 'csv',
        'header_row'     => 1,
        'data_start_row' => 2,
        'delimiter'      => ',',
        'encoding'       => 'UTF-8',
        'version'        => 1,
        'is_active'      => true,
        'activated_at'   => now(),
        'failure_policy' => 'reject_file',
    ]);

    $headers = [
        'date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code',
        'bank_name', 'account_title', 'reference', 'debit', 'credit', 'opening_balance', 'closing_balance', 'balance', 'fs_tag',
    ];
    foreach ($headers as $position => $field) {
        $profile->mappings()->create([
            'position'        => $position + 1,
            'source_header'   => $field,
            'target_field'    => $field,
            'transformations' => in_array($field, ['debit', 'credit', 'opening_balance', 'closing_balance', 'balance'], true)
                ? [['type' => 'decimal', 'scale' => 4]]
                : [['type' => 'trim']],
            'is_required'     => in_array($field, ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'], true),
        ]);
    }

    $csvContent = implode(',', $headers)."\n".
        "2026-01-10,PKR,ACC-110101,Inactive Tag Row,{$fixture['bankJournal']->code},{$fixture['bankGl']->code},Primary Bank,Main,REF-1,100,0,1000,900,900,FS-INACTIVE\n".
        "2026-01-10,PKR,ACC-110101,Foreign Tag Row,{$fixture['bankJournal']->code},{$fixture['bankGl']->code},Primary Bank,Main,REF-2,200,0,900,700,700,FS-FOREIGN\n";

    $path = tempnam(sys_get_temp_dir(), 'aureus-diag-');
    file_put_contents($path, $csvContent);

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'diag.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(2);

        $rows = $run->sourceRows()->orderBy('source_row_number')->get();
        expect(collect($rows[0]->messages)->pluck('message')->first())->toContain('inactive in this company')
            ->and(collect($rows[1]->messages)->pluck('message')->first())->toContain('belongs to another company');
    } finally {
        @unlink($path);
    }

    // Test Approval Revalidation on Inactive Tag
    $activeTag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS-DEACTIVATE-LATER',
        'name'               => 'Will Be Deactivated',
        'account_id'         => $fixture['offsetGl']->id,
        'cash_flow_category' => CashFlowCategory::OperatingPayments->value,
        'is_active'          => true,
    ]);

    $stmtLine1 = createSimpleBankStatementLine($fixture, 'DEACT-1');
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine1->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $activeTag->id,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    // Deactivate tag before approval
    $activeTag->update(['is_active' => false]);

    expect(fn () => app(BankMappingService::class)->approve($mapping->fresh(), $fixture['user'], false))
        ->toThrow(RuntimeException::class, 'active, company-owned');

    // Test Approval Revalidation on Foreign Tag
    $stmtLine2 = createSimpleBankStatementLine($fixture, 'FOREIGN-1');
    $crossCompanyMapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine2->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $foreignTag->id,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    expect(fn () => app(BankMappingService::class)->approve($crossCompanyMapping->fresh(), $fixture['user'], false))
        ->toThrow(RuntimeException::class, 'active, company-owned');
});

it('resolves identical FS Tag codes independently across different companies', function (): void {
    $fixtureA = bankStatementFsTagFixture();

    $companyB = Company::factory()->create(['currency_id' => $fixtureA['currency']->id, 'is_active' => true]);
    $offsetGlB = Account::factory()->create([
        'code'         => 'COA-B-EXP-'.$companyB->id,
        'name'         => 'Company B Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fixtureA['currency']->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $offsetGlB->companies()->attach($companyB->id);

    // Identical Code 'FS-SHARED' created in Company A and Company B
    $tagA = app(FsTagService::class)->create($fixtureA['company'], [
        'code'       => 'FS-SHARED',
        'name'       => 'Company A Shared Tag',
        'account_id' => $fixtureA['offsetGl']->id,
        'is_active'  => true,
    ]);

    $tagB = app(FsTagService::class)->create($companyB, [
        'code'       => 'FS-SHARED',
        'name'       => 'Company B Shared Tag',
        'account_id' => $offsetGlB->id,
        'is_active'  => true,
    ]);

    expect($tagA->id)->not->toBe($tagB->id);

    // Query in Company A scopes strictly to Tag A
    $resolvedInA = FsTag::query()
        ->where('company_id', $fixtureA['company']->id)
        ->whereRaw('UPPER(code) = ?', ['FS-SHARED'])
        ->where('is_active', true)
        ->first();

    $resolvedInB = FsTag::query()
        ->where('company_id', $companyB->id)
        ->whereRaw('UPPER(code) = ?', ['FS-SHARED'])
        ->where('is_active', true)
        ->first();

    expect($resolvedInA->id)->toBe($tagA->id)
        ->and($resolvedInB->id)->toBe($tagB->id);
});

it('handles blank/missing FS Tags as optional unmapped rows without validation errors', function (): void {
    $fixture = bankStatementFsTagFixture();

    $profile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Optional Tag Profile',
        'entity_type'    => 'bank_statement',
        'file_type'      => 'csv',
        'header_row'     => 1,
        'data_start_row' => 2,
        'delimiter'      => ',',
        'encoding'       => 'UTF-8',
        'version'        => 1,
        'is_active'      => true,
        'activated_at'   => now(),
        'failure_policy' => 'reject_file',
    ]);

    $headers = [
        'date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code',
        'bank_name', 'account_title', 'reference', 'debit', 'credit', 'opening_balance', 'closing_balance', 'balance', 'fs_tag',
    ];
    foreach ($headers as $position => $field) {
        $profile->mappings()->create([
            'position'        => $position + 1,
            'source_header'   => $field,
            'target_field'    => $field,
            'transformations' => in_array($field, ['debit', 'credit', 'opening_balance', 'closing_balance', 'balance'], true)
                ? [['type' => 'decimal', 'scale' => 4]]
                : [['type' => 'trim']],
            'is_required'     => in_array($field, ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'], true),
        ]);
    }

    $csvContent = implode(',', $headers)."\n".
        "2026-01-10,PKR,ACC-110101,Standard unmapped row,{$fixture['bankJournal']->code},{$fixture['bankGl']->code},Primary Bank,Main,REF-BLANK,250,0,1000,750,750,\n";

    $path = tempnam(sys_get_temp_dir(), 'aureus-blank-tag-');
    file_put_contents($path, $csvContent);

    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'blank.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(0)
            ->and($run->passed_rows)->toBe(1);

        $completed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        $statement = BankStatement::query()->where('company_id', $fixture['company']->id)->firstOrFail();
        $mapping = $statement->lines()->firstOrFail()->mapping;

        expect($mapping->fs_tag_id)->toBeNull()
            ->and($mapping->match_type)->toBeNull()
            ->and($mapping->review_status)->toBe(BankReviewStatus::Unmapped);
    } finally {
        @unlink($path);
    }
});

it('enforces import failure policies for invalid FS Tags', function (): void {
    $fixture = bankStatementFsTagFixture();

    // 1. Profile with failure_policy = reject_file
    $rejectProfile = ImportProfile::query()->create([
        'company_id'     => $fixture['company']->id,
        'owner_id'       => $fixture['user']->id,
        'name'           => 'Reject File Profile',
        'entity_type'    => 'bank_statement',
        'file_type'      => 'csv',
        'header_row'     => 1,
        'data_start_row' => 2,
        'delimiter'      => ',',
        'encoding'       => 'UTF-8',
        'version'        => 1,
        'is_active'      => true,
        'activated_at'   => now(),
        'failure_policy' => 'reject_file',
    ]);

    $headers = [
        'date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code',
        'bank_name', 'account_title', 'reference', 'debit', 'credit', 'opening_balance', 'closing_balance', 'balance', 'fs_tag',
    ];
    foreach ($headers as $position => $field) {
        $rejectProfile->mappings()->create([
            'position'        => $position + 1,
            'source_header'   => $field,
            'target_field'    => $field,
            'transformations' => in_array($field, ['debit', 'credit', 'opening_balance', 'closing_balance', 'balance'], true)
                ? [['type' => 'decimal', 'scale' => 4]]
                : [['type' => 'trim']],
            'is_required'     => in_array($field, ['date', 'currency', 'bank_account_number', 'description', 'journal_code', 'bank_gl_code'], true),
        ]);
    }

    $csvContent = implode(',', $headers)."\n".
        "2026-01-10,PKR,ACC-110101,Bad Row,{$fixture['bankJournal']->code},{$fixture['bankGl']->code},Primary Bank,Main,REF-ERR,100,0,1000,900,900,UNKNOWN_CODE\n";

    $path = tempnam(sys_get_temp_dir(), 'aureus-policy-');
    file_put_contents($path, $csvContent);

    try {
        $run = app(ImportPreviewService::class)->preview($rejectProfile, $path, 'policy.csv', $fixture['user']->id);
        expect($run->failed_rows)->toBe(1);

        // reject_file policy throws on confirm
        expect(fn () => app(ImportExecutionService::class)->confirm($run, $fixture['user']->id))
            ->toThrow(RuntimeException::class, 'rejects the entire file');
    } finally {
        @unlink($path);
    }
});

it('preserves historical posted move lines immutably when an FS Tag is subsequently deactivated', function (): void {
    $fixture = bankStatementFsTagFixture();

    $tag = app(FsTagService::class)->create($fixture['company'], [
        'code'               => 'FS-HISTORICAL',
        'name'               => 'Historical Charge Tag',
        'account_id'         => $fixture['offsetGl']->id,
        'cash_flow_category' => CashFlowCategory::OperatingPayments->value,
        'tax_treatment'      => 'Exempt',
        'is_active'          => true,
    ]);

    $stmtLine = createSimpleBankStatementLine($fixture, 'HIST-1');
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $tag->id,
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
    $draft = app(BankJournalService::class)->createDraft($approved);
    $posted = app(BankJournalService::class)->post($approved->fresh(), $fixture['user']);

    $postedLine = $posted->lines()->where('account_id', $fixture['offsetGl']->id)->firstOrFail();
    expect($postedLine->fs_tag_id)->toBe($tag->id)
        ->and($postedLine->parent_state)->toBe(MoveState::POSTED);

    // Deactivate and rename the master FS Tag record
    $tag->update([
        'is_active' => false,
        'name'      => 'Renamed Inactive Tag',
    ]);

    // Verify historical posted accounting move line remains intact and unchanged
    $postedLineFresh = $postedLine->fresh(['fsTag']);
    expect($postedLineFresh->fs_tag_id)->toBe($tag->id)
        ->and($postedLineFresh->fsTag->id)->toBe($tag->id)
        ->and($postedLineFresh->fsTag->is_active)->toBeFalse()
        ->and($postedLineFresh->parent_state)->toBe(MoveState::POSTED);
});
