<?php

/**
 * DriveSyncExportTest only ever uses DocumentType::Other/::Receipt (both
 * fall to config/accounting_drive.php's 'default' template, no
 * {identifier} segment), so DriveFolderPathResolver's actual per-type
 * templates and identifierFor()/sanitize() logic had zero direct coverage
 * -- flagged by review, closed here with a unit-level test against the
 * resolver directly, no DocumentService/DriveSyncService/Drive API
 * involved.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Database\Factories\BankStatementFactory;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Bill as AccountingBill;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\Invoice as AccountingInvoice;
use Webkul\Accounting\Models\JournalEntry;
use Webkul\Accounting\Support\DriveFolderPathResolver;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );
    Package::$plugins = Plugin::all()->keyBy('name');

    Config::set('accounting_drive.unify_invoice_and_inbound_folders', false);

    $this->resolver = new DriveFolderPathResolver;
    $this->company = Company::factory()->create(['is_active' => true]);
});

it('resolves the Invoice template to Customer Invoices folder with Year and Month', function () {
    $move = Move::factory()->create([
        'name'         => 'INV/2026/00042',
        'move_type'    => MoveType::OUT_INVOICE,
        'company_id'   => $this->company->id,
        'currency_id'  => Currency::query()->firstOrFail()->id,
        'invoice_date' => '2026-10-15',
    ]);
    $invoice = AccountingInvoice::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingInvoice::class,
        'attachable_id'   => $invoice->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Customer Invoices',
        '2026',
        '10-October',
    ]);
});

it('resolves the Bill template to Vendor Bills folder with Year and Month', function () {
    $move = Move::factory()->create([
        'name'         => 'BILL/2026/00017',
        'move_type'    => MoveType::IN_INVOICE,
        'company_id'   => $this->company->id,
        'currency_id'  => Currency::query()->firstOrFail()->id,
        'invoice_date' => '2026-10-20',
    ]);
    $bill = AccountingBill::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Bill,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingBill::class,
        'attachable_id'   => $bill->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Vendor Bills',
        '2026',
        '10-October',
    ]);
});

it('resolves the Journal Entry template with Year and Month', function () {
    $move = Move::factory()->create([
        'name'        => 'JE/2026/00042',
        'move_type'   => MoveType::ENTRY,
        'company_id'  => $this->company->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
        'date'        => '2026-10-05',
    ]);
    $entry = JournalEntry::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::JournalEntry,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => JournalEntry::class,
        'attachable_id'   => $entry->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Journal Entries',
        '2026',
        '10-October',
    ]);
});

it('resolves the Payment Evidence template to Supporting Documents folder', function () {
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::PaymentEvidence,
        'created_at'    => '2026-10-05 12:00:00',
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Supporting Documents',
        '2026',
        '10-October',
    ]);
});

it('resolves the Bank Statement template with Year and Month', function () {
    $statement = BankStatementFactory::new()->accountingModule([
        'company_id' => $this->company->id,
        'name'       => 'HBL Statement Aug/2026',
        'date'       => '2026-08-15',
    ])->create();

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::BankStatement,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => $statement::class,
        'attachable_id'   => $statement->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Bank Statements',
        '2026',
        '08-August',
    ]);
});

it('falls back to Supporting Documents for a document type with no template entry', function () {
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Other,
        'created_at'    => '2026-10-05 12:00:00',
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Supporting Documents',
    ]);
});

it('supports templates with identifier placeholder and falls back to document-id when unattached', function () {
    Config::set('accounting_drive.path_templates.invoice', ['{company}', 'Custom', '{identifier}']);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
    ]);

    $path = $this->resolver->resolve($document);

    expect(end($path))->toBe("document-{$document->id}");
});

it('sanitizes a slash-bearing company name so it can never introduce an extra folder level', function () {
    $company = Company::factory()->create(['is_active' => true, 'name' => 'Trade Debtors / Local']);

    $document = Document::factory()->create([
        'company_id'    => $company->id,
        'document_type' => DocumentType::Other,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path[1])->toBe("Trade Debtors - Local ({$company->id})")
        ->and($path[1])->not->toContain('/');
});

it('resolves Invoice to the company inbound folder when unify_invoice_and_inbound_folders is enabled', function () {
    Config::set('accounting_drive.unify_invoice_and_inbound_folders', true);

    $move = Move::factory()->create([
        'name'        => 'INV/2026/00042',
        'move_type'   => MoveType::OUT_INVOICE,
        'company_id'  => $this->company->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
    ]);
    $invoice = AccountingInvoice::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingInvoice::class,
        'attachable_id'   => $invoice->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Inbound',
    ]);
});

it('resolves the Paid Invoices folder under the company directory', function () {
    $path = $this->resolver->resolvePaidFolder($this->company);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Paid Invoices',
    ]);
});
