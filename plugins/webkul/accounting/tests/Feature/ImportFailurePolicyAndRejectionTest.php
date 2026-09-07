<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\ImportFailurePolicy;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function importFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $currency->update(['active' => true, 'is_iso_fiat' => true]);

    $company = Company::factory()->create([
        'currency_id' => $currency->id,
        'is_active'   => true,
    ]);

    $companyB = Company::factory()->create([
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

    $expenseGl = Account::factory()->create([
        'code'         => 'EXP100'.$company->id,
        'name'         => 'Operating Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $expenseGl->companies()->attach($company->id);

    $bankGl = Account::factory()->create([
        'code'         => 'BNK100'.$company->id,
        'name'         => 'Operating Bank',
        'account_type' => AccountType::ASSET_CASH,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $bankGl->companies()->attach($company->id);

    $journal = Journal::query()->create([
        'company_id'         => $company->id,
        'currency_id'        => $currency->id,
        'default_account_id' => $expenseGl->id,
        'name'               => 'General Operations',
        'code'               => 'GEN'.$company->id,
        'type'               => 'general',
    ]);

    return compact('currency', 'company', 'companyB', 'user', 'expenseGl', 'bankGl', 'journal');
}

function createTempCsv(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'aureus-test-import-');
    $handle = fopen($path, 'w');
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

function createOpeningBalanceProfile(Company $company, ImportFailurePolicy $policy): ImportProfile
{
    $profile = ImportProfile::query()->create([
        'company_id'     => $company->id,
        'name'           => 'Opening Balance Import '.$policy->value.' '.uniqid(),
        'entity_type'    => 'opening_balance',
        'file_type'      => 'csv',
        'failure_policy' => $policy->value,
        'version'        => 1,
        'is_active'      => true,
    ]);

    $profile->mappings()->createMany([
        ['source_header' => 'Journal Code', 'target_field' => 'journal_code', 'position' => 1, 'is_required' => true],
        ['source_header' => 'GL Code', 'target_field' => 'gl_code', 'position' => 2, 'is_required' => true],
        ['source_header' => 'Date', 'target_field' => 'date', 'position' => 3, 'is_required' => true],
        ['source_header' => 'Debit', 'target_field' => 'debit', 'position' => 4],
        ['source_header' => 'Credit', 'target_field' => 'credit', 'position' => 5],
        ['source_header' => 'Currency', 'target_field' => 'currency', 'position' => 6],
        ['source_header' => 'FS Tag', 'target_field' => 'fs_tag', 'position' => 7],
    ]);

    return $profile;
}

function createOpeningBalanceProfileWithMemo(Company $company, ImportFailurePolicy $policy): ImportProfile
{
    $profile = createOpeningBalanceProfile($company, $policy);
    $profile->mappings()->create([
        'source_header' => 'Memo', 'target_field' => 'memo', 'position' => 8, 'is_required' => true,
    ]);

    return $profile;
}

/* =========================================================================
 * 1. FAILURE POLICY TESTS (reject_file, reject_failed_rows, needs_review, warn_continue)
 * ========================================================================= */

it('enforces reject_file policy atomically leaving 0 business records on failure (M7, R1)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFile);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', ''],
        [$fixture['journal']->code, $fixture['bankGl']->code, '2026-09-01', '0', '100', 'PKR', ''],
        [$fixture['journal']->code, 'INVALID-GL-999', '2026-09-01', '200', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);
        expect($run->passed_rows)->toBe(2)
            ->and($run->failed_rows)->toBe(1);

        // Confirming must throw RuntimeException and leave ZERO moves
        expect(fn () => app(ImportExecutionService::class)->confirm($run, $fixture['user']->id))
            ->toThrow(RuntimeException::class, 'rejects the entire file');

        expect(Move::query()->where('company_id', $fixture['company']->id)->count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('imports valid rows and isolates failed rows under reject_failed_rows policy (M7, M8)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFailedRows);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', ''],
        [$fixture['journal']->code, $fixture['bankGl']->code, '2026-09-01', '0', '100', 'PKR', ''],
        [$fixture['journal']->code, 'INVALID-GL-999', '2026-09-01', '200', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);
        $confirmed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);

        expect($confirmed->status)->toBe('completed_with_rejections')
            ->and($confirmed->imported_rows)->toBe(2);

        expect(Move::query()->where('company_id', $fixture['company']->id)->count())->toBe(1);

        // Export rejected rows CSV
        $csv = app(ImportExecutionService::class)->exportRejectedRows($confirmed);
        expect($csv)->toContain('INVALID-GL-999')
            ->and($csv)->not->toContain($fixture['expenseGl']->code);
    } finally {
        @unlink($path);
    }
});

it('sets status to completed_with_review under needs_review policy (M7)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::NeedsReview);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', ''],
        [$fixture['journal']->code, $fixture['bankGl']->code, '2026-09-01', '0', '100', 'PKR', ''],
        [$fixture['journal']->code, 'INVALID-GL-888', '2026-09-01', '200', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);
        $confirmed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);

        expect($confirmed->status)->toBe('completed_with_review')
            ->and($confirmed->imported_rows)->toBe(2);
    } finally {
        @unlink($path);
    }
});

/* =========================================================================
 * 2. REJECTED-ROW CSV EXPORT TESTS (M8, R3, R5, R6)
 * ========================================================================= */

it('exports rejected rows with exact persisted validation reasons and escapes special characters (M8, R6)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFailedRows);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, 'INVALID, "GL" Code', '2026-09-01', '100', '0', 'PKR', 'BAD-TAG'],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);
        $csv = app(ImportExecutionService::class)->exportRejectedRows($run);

        expect($csv)->toContain('rejection_reason')
            ->and($csv)->toContain('"INVALID, ""GL"" Code"')
            ->and($csv)->toContain('gl_code: The GL code is not an active postable account in this company');
    } finally {
        @unlink($path);
    }
});

it('preserves historical rejection reasons even if master data subsequently changes (R3)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFailedRows);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, 'FUTURE-GL', '2026-09-01', '100', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);

        // Create FUTURE-GL in database afterwards
        $newAccount = Account::factory()->create([
            'code'         => 'FUTURE-GL',
            'name'         => 'Future Account',
            'account_type' => AccountType::EXPENSE,
            'currency_id'  => $fixture['currency']->id,
        ]);
        $newAccount->companies()->attach($fixture['company']->id);

        // Exporting rejected rows must still output the historical reason recorded at preview time
        $csv = app(ImportExecutionService::class)->exportRejectedRows($run);
        expect($csv)->toContain('FUTURE-GL')
            ->and($csv)->toContain('gl_code: The GL code is not an active postable account in this company');
    } finally {
        @unlink($path);
    }
});

it('guarantees exportRejectedRows idempotency without mutating database state (R5)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFailedRows);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, 'BAD-GL', '2026-09-01', '100', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);

        $csv1 = app(ImportExecutionService::class)->exportRejectedRows($run);
        $csv2 = app(ImportExecutionService::class)->exportRejectedRows($run);

        expect($csv1)->toBe($csv2)
            ->and($run->fresh()->status)->toBe('previewed');
    } finally {
        @unlink($path);
    }
});

/* =========================================================================
 * 3. FINGERPRINT DEDUPLICATION LIFECYCLE TESTS (B12)
 * ========================================================================= */

it('ensures error rows in preview do not poison duplicate detection: ERROR -> VALID -> DUPLICATE (B12)', function (): void {
    $fixture = importFixture();

    $tag = app(FsTagService::class)->create($fixture['company'], [
        'code'       => 'FS-FINGERPRINT',
        'name'       => 'Fingerprint Tag',
        'account_id' => $fixture['expenseGl']->id,
        'is_active'  => false, // Inactive initially
    ]);

    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFailedRows);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        // Row 1: ERROR (Inactive FS Tag)
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', 'FS-FINGERPRINT'],
        // Row 2: VALID (No FS Tag)
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', ''],
        // Row 3: DUPLICATE (Identical to valid Row 2)
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);

        $sourceRows = $run->sourceRows()->orderBy('source_row_number')->get();

        expect($sourceRows[0]->status)->toBe('error') // Row 1 is Error
            ->and($sourceRows[1]->status)->toBe('pass') // Row 2 is Valid (NOT poisoned by Row 1!)
            ->and($sourceRows[2]->status)->toBe('duplicate'); // Row 3 is Duplicate of Row 2
    } finally {
        @unlink($path);
    }
});

/* =========================================================================
 * 4. CASE-INSENSITIVE GL RESOLUTION TESTS (B11)
 * ========================================================================= */

it('resolves lowercase, uppercase, and mixed-case GL codes during confirm while enforcing company scope and postable status (B11, R2)', function (): void {
    $fixture = importFixture();
    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::RejectFailedRows);

    $glCode = $fixture['expenseGl']->code;
    $lowerGl = strtolower($glCode);
    $mixedGl = ucfirst(strtolower($glCode));

    $bankCode = $fixture['bankGl']->code;

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, $lowerGl, '2026-09-01', '100', '0', 'PKR', ''],
        [$fixture['journal']->code, $mixedGl, '2026-09-01', '200', '0', 'PKR', ''],
        [$fixture['journal']->code, $bankCode, '2026-09-01', '0', '300', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'test.csv', $fixture['user']->id);
        expect($run->passed_rows)->toBe(3)
            ->and($run->failed_rows)->toBe(0);

        $confirmed = app(ImportExecutionService::class)->confirm($run, $fixture['user']->id);
        expect($confirmed->imported_rows)->toBe(3);

        // Cross-company isolation test: Account from Company B with same code must NOT resolve in Company A
        $companyBAccount = Account::factory()->create([
            'code'         => 'COMP-B-EXP',
            'name'         => 'Company B Only',
            'account_type' => AccountType::EXPENSE,
            'currency_id'  => $fixture['currency']->id,
        ]);
        $companyBAccount->companies()->attach($fixture['companyB']->id);

        $crossRows = [
            [$fixture['journal']->code, 'COMP-B-EXP', '2026-09-01', '100', '0', 'PKR', ''],
        ];
        $crossPath = createTempCsv($headers, $crossRows);
        try {
            $crossRun = app(ImportPreviewService::class)->preview($profile, $crossPath, 'cross.csv', $fixture['user']->id);
            expect($crossRun->failed_rows)->toBe(1)
                ->and($crossRun->passed_rows)->toBe(0);
        } finally {
            @unlink($crossPath);
        }
    } finally {
        @unlink($path);
    }
});

/* =========================================================================
 * 5. WARN-AND-CONTINUE vs REJECT-FAILED-ROWS DIFFERENTIATION
 * ========================================================================= */

it('downgrades only a BusinessRule-flagged non-critical field to warning under warn_continue, unlike reject_failed_rows', function (): void {
    $fixture = importFixture();

    // A rule that only matches rows with a blank memo (so it leaves the clean balancing
    // row untouched) and marks the non-integrity "memo" field non-critical. Global
    // (profile_id null) so it applies under both profiles below.
    BusinessRule::query()->create([
        'company_id'  => $fixture['company']->id,
        'entity_type' => 'opening_balance',
        'name'        => 'Memo is optional under warn-and-continue',
        'conditions'  => [['field' => 'memo', 'operator' => 'blank']],
        'actions'     => [['type' => 'mark_non_critical', 'field' => 'memo']],
        'is_active'   => true,
    ]);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag', 'Memo'];
    $rows = [
        // Memo is left blank — the only failure on this row.
        [$fixture['journal']->code, $fixture['expenseGl']->code, '2026-09-01', '100', '0', 'PKR', '', ''],
        // Balancing credit leg, memo populated — always passes cleanly.
        [$fixture['journal']->code, $fixture['bankGl']->code, '2026-09-01', '0', '100', 'PKR', '', 'Balancing leg'],
    ];

    $rejectProfile = createOpeningBalanceProfileWithMemo($fixture['company'], ImportFailurePolicy::RejectFailedRows);
    $rejectPath = createTempCsv($headers, $rows);
    try {
        $rejectRun = app(ImportPreviewService::class)->preview($rejectProfile, $rejectPath, 'reject.csv', $fixture['user']->id);
        expect($rejectRun->sourceRows()->orderBy('source_row_number')->first()->status)->toBe('error')
            ->and($rejectRun->passed_rows)->toBe(1)
            ->and($rejectRun->failed_rows)->toBe(1);
    } finally {
        @unlink($rejectPath);
    }

    $warnProfile = createOpeningBalanceProfileWithMemo($fixture['company'], ImportFailurePolicy::WarnContinue);
    $warnPath = createTempCsv($headers, $rows);
    try {
        $warnRun = app(ImportPreviewService::class)->preview($warnProfile, $warnPath, 'warn.csv', $fixture['user']->id);
        $row = $warnRun->sourceRows()->orderBy('source_row_number')->first();
        expect($row->status)->toBe('warning')
            ->and($warnRun->passed_rows)->toBe(1)
            ->and($warnRun->warning_rows)->toBe(1)
            ->and($warnRun->failed_rows)->toBe(0);

        // Both rows now import (the balanced opening-balance journal) rather than one being excluded.
        $confirmed = app(ImportExecutionService::class)->confirm($warnRun, $fixture['user']->id);
        expect($confirmed->imported_rows)->toBe(2);
    } finally {
        @unlink($warnPath);
    }
});

it('never downgrades a hard accounting-integrity failure (unknown GL) under warn_continue', function (): void {
    $fixture = importFixture();

    // Even a rule that (mis)targets a hard-integrity field must not weaken it.
    BusinessRule::query()->create([
        'company_id'  => $fixture['company']->id,
        'entity_type' => 'opening_balance',
        'name'        => 'Attempted GL downgrade (must have no effect)',
        'conditions'  => [['field' => 'journal_code', 'operator' => 'not_blank']],
        'actions'     => [['type' => 'mark_non_critical', 'field' => 'gl_code']],
        'is_active'   => true,
    ]);

    $profile = createOpeningBalanceProfile($fixture['company'], ImportFailurePolicy::WarnContinue);

    $headers = ['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency', 'FS Tag'];
    $rows = [
        [$fixture['journal']->code, 'UNKNOWN-GL-CODE', '2026-09-01', '100', '0', 'PKR', ''],
    ];

    $path = createTempCsv($headers, $rows);
    try {
        $run = app(ImportPreviewService::class)->preview($profile, $path, 'warn-gl.csv', $fixture['user']->id);
        expect($run->sourceRows()->first()->status)->toBe('error')
            ->and($run->passed_rows)->toBe(0)
            ->and($run->failed_rows)->toBe(1);
    } finally {
        @unlink($path);
    }
});
