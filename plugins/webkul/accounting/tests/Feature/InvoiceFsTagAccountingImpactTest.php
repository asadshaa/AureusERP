<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Contracts\DriveClient;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveDocumentType;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\DriveIngestionClassification;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Drive\DriveIngestionService;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Accounting\Tests\Helpers\FakeDriveClient;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../Helpers/DocumentTestHelper.php';
require_once __DIR__.'/../Helpers/FakeDriveClient.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    Storage::fake('accounting_documents');

    Config::set('accounting_drive.enabled', true);
    Config::set('accounting_drive.shared_drive_id', null);
    Config::set('accounting_drive.root_folder_name', 'Aureus');
    Config::set('accounting_drive.inbound_folder_name', 'Inbound');
    Config::set('queue.default', 'sync');

    $this->fakeDrive = new FakeDriveClient;
    app()->instance(DriveClient::class, $this->fakeDrive);
});

it('proves invoice ingestion carries FS tag classification through Invoice -> Journal -> GL -> Trial Balance -> P&L with exact mathematical reconciliation', function () {
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    $user = documentTestUser($company, [AccountingPermissions::ManageDocuments]);
    test()->actingAs($user);

    $accountFor = function (string $code, AccountType $type, bool $reconcile = false) use ($company, $currency): Account {
        $account = Account::factory()->create([
            'code'         => $code.uniqid(),
            'name'         => $code,
            'account_type' => $type,
            'currency_id'  => $currency->id,
            'is_group'     => false,
            'deprecated'   => false,
            'reconcile'    => $reconcile,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };

    $arAccount = $accountFor('121000', AccountType::ASSET_RECEIVABLE, reconcile: true);
    $apAccount = $accountFor('211000', AccountType::LIABILITY_PAYABLE, reconcile: true);
    $revAccount = $accountFor('400000', AccountType::INCOME);
    $fuelAccount = $accountFor('613000', AccountType::EXPENSE);
    $equipAccount = $accountFor('611000', AccountType::EXPENSE);

    Journal::factory()->create([
        'company_id' => $company->id, 'currency_id' => $currency->id,
        'type'       => JournalType::SALE, 'code' => 'INV'.uniqid(),
    ]);
    Journal::factory()->create([
        'company_id' => $company->id, 'currency_id' => $currency->id,
        'type'       => JournalType::PURCHASE, 'code' => 'BILL'.uniqid(),
    ]);

    $revTag = FsTag::query()->create([
        'company_id' => $company->id, 'account_id' => $revAccount->id,
        'code'       => 'FS-MANUAL-REV', 'name' => 'Operating Revenue', 'is_active' => true,
    ]);
    $fuelTag = FsTag::query()->create([
        'company_id' => $company->id, 'account_id' => $fuelAccount->id,
        'code'       => 'FS-FUEL', 'name' => 'Fuel Expenses', 'is_active' => true,
    ]);
    $equipTag = FsTag::query()->create([
        'company_id' => $company->id, 'account_id' => $equipAccount->id,
        'code'       => 'FS-EQUIP', 'name' => 'IT & Equipment Expense', 'is_active' => true,
    ]);

    $partnerAcme = Partner::factory()->create(['company_id' => $company->id, 'name' => 'Acme Corporation']);
    $partnerDell = Partner::factory()->create(['company_id' => $company->id, 'name' => 'Dell Technologies']);

    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id, 'name' => 'Drive ingestion classification approval',
        'request_type' => 'drive_ingestion_classification', 'is_active' => true,
    ]);
    $workflow->steps()->create([
        'sequence'         => 1, 'name' => 'Finance review',
        'approver_user_id' => $user->id, 'required_approvals' => 1,
    ]);

    // 1. Baseline verification
    $tbService = app(TrialBalanceService::class);
    $baseTb = $tbService->compute($company->id, '2026-01-01', '2026-12-31');
    expect($baseTb['totals']['difference'])->toBe(0.0);

    // 2 & 3. Create test invoices and ingest through Drive workflow
    $rootId = $this->fakeDrive->findFolder('Aureus', null) ?? $this->fakeDrive->createFolder('Aureus', null);
    $companyFolderId = $this->fakeDrive->findFolder("{$company->name} ({$company->id})", $rootId)
        ?? $this->fakeDrive->createFolder("{$company->name} ({$company->id})", $rootId);
    $inboundFolderId = $this->fakeDrive->findFolder('Inbound', $companyFolderId)
        ?? $this->fakeDrive->createFolder('Inbound', $companyFolderId);

    $testCases = [
        [
            'filename'       => 'INV-202610-001_Acme_15000.00PKR_2026-10-06_FS-MANUAL-REV.pdf',
            'expected_tag'   => $revTag,
            'expected_acc'   => $revAccount,
            'amount'         => 15000.00,
            'doc_type'       => DriveDocumentType::CustomerInvoice,
        ],
        [
            'filename'       => 'BILL-202610-002_Dell_7500.00PKR_2026-10-06_FS-FUEL.pdf',
            'expected_tag'   => $fuelTag,
            'expected_acc'   => $fuelAccount,
            'amount'         => 7500.00,
            'doc_type'       => DriveDocumentType::VendorBill,
        ],
        [
            'filename'       => 'BILL-202610-003_Dell_3200.00PKR_2026-10-06_FS-EQUIP.pdf',
            'expected_tag'   => $equipTag,
            'expected_acc'   => $equipAccount,
            'amount'         => 3200.00,
            'doc_type'       => DriveDocumentType::VendorBill,
        ],
    ];

    $ingestionService = app(DriveIngestionService::class);
    $approvalEngine = app(ApprovalEngine::class);
    $postedMoves = [];

    foreach ($testCases as $tc) {
        $fileId = $this->fakeDrive->putExternalFile(
            $tc['filename'],
            'application/pdf',
            '%PDF-1.4 test invoice content',
            $inboundFolderId
        );

        $touched = $ingestionService->discover($company);
        $ingestion = DriveIngestion::query()->where('drive_file_id', $fileId)->firstOrFail();
        $ingestionService->download($ingestion);
        $ingestionService->register($ingestion);

        // Classification assertion: FS Tag detected by code
        $classification = DriveIngestionClassification::query()
            ->where('drive_ingestion_id', $ingestion->id)
            ->firstOrFail();

        expect($classification->validation_status)->toBe(DriveClassificationStatus::Valid)
            ->and($classification->resolved_fs_tag_id)->toBe($tc['expected_tag']->id)
            ->and((float) $classification->extracted_amount)->toBe($tc['amount']);

        // 4. Approve & Post
        $approvalEngine->approve($classification->approvalRequest, $user);
        $classification->refresh();

        expect($classification->validation_status)->toBe(DriveClassificationStatus::Posted)
            ->and($classification->created_invoice_id)->not->toBeNull();

        $move = $classification->createdInvoice;
        expect($move->state)->toBe(MoveState::POSTED)
            ->and((float) $move->amount_total)->toBe($tc['amount']);

        // Verify FS Tag on MoveLine
        $taggedLine = $move->lines->firstWhere('account_id', $tc['expected_acc']->id);
        expect($taggedLine)->not->toBeNull()
            ->and($taggedLine->fs_tag_id)->toBe($tc['expected_tag']->id);

        // Move is balanced
        $dr = $move->lines->sum(fn (MoveLine $l) => (float) $l->debit);
        $cr = $move->lines->sum(fn (MoveLine $l) => (float) $l->credit);
        expect(round($dr - $cr, 2))->toBe(0.0);

        $postedMoves[] = $move;
    }

    expect($postedMoves)->toHaveCount(3);

    // 5. Accounting Impact Verification
    $afterTb = $tbService->compute($company->id, '2026-01-01', '2026-12-31');
    expect($afterTb['totals']['difference'])->toBe(0.0)
        ->and($afterTb['totals']['movement_debit'])->toBe(25700.0)
        ->and($afterTb['totals']['movement_credit'])->toBe(25700.0);

    // 6. P&L Classification Verification
    $incomeTypes = array_keys(AccountType::income());
    $expenseTypes = array_keys(AccountType::expenses());

    $postedLines = MoveLine::whereIn('move_id', collect($postedMoves)->pluck('id'))->get();
    $incomeSum = $postedLines->filter(fn ($l) => in_array($l->account->account_type->value, $incomeTypes))->sum('credit');
    $expenseSum = $postedLines->filter(fn ($l) => in_array($l->account->account_type->value, $expenseTypes))->sum('debit');

    expect((float) $incomeSum)->toBe(15000.0)
        ->and((float) $expenseSum)->toBe(10700.0)
        ->and(round($incomeSum - $expenseSum, 2))->toBe(4300.0);
});
