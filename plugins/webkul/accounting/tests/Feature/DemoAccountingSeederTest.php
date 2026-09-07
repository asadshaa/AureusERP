<?php

use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Database\Seeders\DemoAccountingSeeder;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;

it('seeds a complete, coherent accounting dataset', function () {
    $seeder = app(DemoAccountingSeeder::class);
    $seeder->run();

    $company1 = Company::query()->where('name', 'Truck It In (Demo)')->first();
    $company2 = Company::query()->where('name', 'Rider Demo')->first();

    expect($company1)->not->toBeNull()
        ->and($company2)->not->toBeNull()
        ->and($company1->currency->code)->toBe('PKR')
        ->and($company2->currency->code)->toBe('PKR');

    // Company 1 has 62 postable accounts and >100 group accounts
    $postableCount = Account::query()
        ->whereHas('companies', fn ($q) => $q->where('companies.id', $company1->id))
        ->where('is_group', false)
        ->count();

    $groupCount = Account::query()
        ->whereHas('companies', fn ($q) => $q->where('companies.id', $company1->id))
        ->where('is_group', true)
        ->count();

    expect($postableCount)->toBe(62)
        ->and($groupCount)->toBeGreaterThan(100);

    // Company 1 has >=10 active FS Tags, plus exactly 1 inactive one
    $activeTags = FsTag::query()->where('company_id', $company1->id)->where('is_active', true)->count();
    $inactiveTags = FsTag::query()->where('company_id', $company1->id)->where('is_active', false)->count();

    expect($activeTags)->toBeGreaterThanOrEqual(10)
        ->and($inactiveTags)->toBe(1);

    // The three migration journals exist, are posted, and each balances (Dr == Cr)
    $migrationMoves = Move::query()
        ->where('company_id', $company1->id)
        ->whereNotNull('coa_migration_kind')
        ->get();

    expect($migrationMoves->count())->toBe(3);
    foreach ($migrationMoves as $m) {
        expect($m->state)->toBe(MoveState::POSTED);
        $totalDr = (float) $m->lines()->sum('debit');
        $totalCr = (float) $m->lines()->sum('credit');
        expect(abs($totalDr - $totalCr))->toBeLessThan(0.01);
    }

    // 4 customer invoices and 2 vendor bills exist and are posted
    $postedInvoices = Move::query()->where('company_id', $company1->id)->where('move_type', 'out_invoice')->where('state', MoveState::POSTED)->count();
    $postedBills = Move::query()->where('company_id', $company1->id)->where('move_type', 'in_invoice')->where('state', MoveState::POSTED)->count();

    expect($postedInvoices)->toBe(4)
        ->and($postedBills)->toBe(2);

    // >=12 BankTransactionMapping rows exist, none posted, mixed review states
    $mappings = BankTransactionMapping::query()->where('company_id', $company1->id)->get();
    expect($mappings->count())->toBeGreaterThanOrEqual(12);

    $postedMappings = $mappings->filter(fn ($m) => $m->posting_status->value === 'posted')->count();
    expect($postedMappings)->toBe(0);

    $suggestedCount = $mappings->filter(fn ($m) => $m->review_status === BankReviewStatus::Suggested)->count();
    $approvedCount = $mappings->filter(fn ($m) => $m->review_status === BankReviewStatus::Approved)->count();
    $needsReviewCount = $mappings->filter(fn ($m) => $m->review_status === BankReviewStatus::NeedsReview)->count();

    expect($suggestedCount)->toBeGreaterThan(0)
        ->and($approvedCount)->toBeGreaterThan(0)
        ->and($needsReviewCount)->toBeGreaterThan(0);

    // ApprovalWorkflows exist for bank_transaction_mapping and manual_adjustment
    $wf1 = ApprovalWorkflow::query()->where('company_id', $company1->id)->where('request_type', 'bank_transaction_mapping')->first();
    $wf2 = ApprovalWorkflow::query()->where('company_id', $company1->id)->where('request_type', 'manual_adjustment')->first();

    expect($wf1)->not->toBeNull()
        ->and($wf1->steps()->count())->toBeGreaterThanOrEqual(1)
        ->and($wf2)->not->toBeNull()
        ->and($wf2->steps()->count())->toBeGreaterThanOrEqual(1);
});

it('enforces multi-company isolation', function () {
    $seeder = app(DemoAccountingSeeder::class);
    $seeder->run();

    $company1 = Company::query()->where('name', 'Truck It In (Demo)')->firstOrFail();
    $company2 = Company::query()->where('name', 'Rider Demo')->firstOrFail();

    // The same GL code exists in both companies and resolves to different account IDs
    $co1Account = Account::query()
        ->whereHas('companies', fn ($q) => $q->where('companies.id', $company1->id))
        ->where('is_group', false)
        ->whereNotNull('code')
        ->firstOrFail();

    $co2Account = Account::query()
        ->whereHas('companies', fn ($q) => $q->where('companies.id', $company2->id))
        ->where('code', $co1Account->code)
        ->firstOrFail();

    expect($co1Account->id)->not->toBe($co2Account->id);

    // Company 2 cannot see Company 1's FS Tags
    $resolvedInCo2 = app(FsTagService::class)->resolve($company2->id, 'FS-BANK-FEE');
    expect($resolvedInCo2)->toBeNull();
});

it('guarantees strict idempotency when run twice', function () {
    $seeder = app(DemoAccountingSeeder::class);

    // First run
    $seeder->run();

    $company1 = Company::query()->where('name', 'Truck It In (Demo)')->firstOrFail();

    $accCountBefore = Account::query()->whereHas('companies', fn ($q) => $q->where('companies.id', $company1->id))->count();
    $tagCountBefore = FsTag::query()->where('company_id', $company1->id)->count();
    $moveCountBefore = Move::query()->where('company_id', $company1->id)->count();
    $mapCountBefore = BankTransactionMapping::query()->where('company_id', $company1->id)->count();
    $wfCountBefore = ApprovalWorkflow::query()->where('company_id', $company1->id)->count();

    // Second run
    $seeder->run();

    $accCountAfter = Account::query()->whereHas('companies', fn ($q) => $q->where('companies.id', $company1->id))->count();
    $tagCountAfter = FsTag::query()->where('company_id', $company1->id)->count();
    $moveCountAfter = Move::query()->where('company_id', $company1->id)->count();
    $mapCountAfter = BankTransactionMapping::query()->where('company_id', $company1->id)->count();
    $wfCountAfter = ApprovalWorkflow::query()->where('company_id', $company1->id)->count();

    expect($accCountAfter)->toBe($accCountBefore)
        ->and($tagCountAfter)->toBe($tagCountBefore)
        ->and($moveCountAfter)->toBe($moveCountBefore)
        ->and($mapCountAfter)->toBe($mapCountBefore)
        ->and($wfCountAfter)->toBe($wfCountBefore);
});

it('maintains Trial Balance integrity with zero difference', function () {
    $seeder = app(DemoAccountingSeeder::class);
    $seeder->run();

    $company1 = Company::query()->where('name', 'Truck It In (Demo)')->firstOrFail();

    $tb = app(TrialBalanceService::class)->compute($company1->id, '2026-07-01', '2026-07-31');

    $closingDebit = (float) ($tb['totals']['closing_debit'] ?? 0);
    $closingCredit = (float) ($tb['totals']['closing_credit'] ?? 0);

    expect(abs($closingDebit - $closingCredit))->toBeLessThan(0.01);
});
