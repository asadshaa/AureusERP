<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Bill as AccountingBill;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\Invoice as AccountingInvoice;
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
    $this->currency = Currency::query()->firstOrFail();
    $this->company = Company::factory()->create([
        'name'        => 'Truck It In',
        'is_active'   => true,
        'currency_id' => $this->currency->id,
    ]);

    // Setup Receivable, Payable, Income, and Expense Accounts for the company
    $this->receivableAccount = Account::factory()->create([
        'name'         => 'Trade Debtors',
        'code'         => '1100',
        'account_type' => AccountType::ASSET_RECEIVABLE,
        'currency_id'  => $this->currency->id,
    ]);
    $this->receivableAccount->companies()->attach($this->company->id);

    $this->payableAccount = Account::factory()->create([
        'name'         => 'Trade Creditors',
        'code'         => '2100',
        'account_type' => AccountType::LIABILITY_PAYABLE,
        'currency_id'  => $this->currency->id,
    ]);
    $this->payableAccount->companies()->attach($this->company->id);

    $this->incomeAccount = Account::factory()->create([
        'name'         => 'Freight Revenue',
        'code'         => '4000',
        'account_type' => AccountType::INCOME,
        'currency_id'  => $this->currency->id,
    ]);
    $this->incomeAccount->companies()->attach($this->company->id);

    $this->expenseAccount = Account::factory()->create([
        'name'         => 'Fuel Expense',
        'code'         => '5000',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $this->currency->id,
    ]);
    $this->expenseAccount->companies()->attach($this->company->id);

    // Setup Sales and Purchase Journals
    $this->salesJournal = Journal::factory()->create([
        'name'               => 'Customer Invoices Journal',
        'code'               => 'INV',
        'type'               => JournalType::SALE,
        'company_id'         => $this->company->id,
        'currency_id'        => $this->currency->id,
        'default_account_id' => $this->incomeAccount->id,
    ]);

    $this->purchaseJournal = Journal::factory()->create([
        'name'               => 'Vendor Bills Journal',
        'code'               => 'BILL',
        'type'               => JournalType::PURCHASE,
        'company_id'         => $this->company->id,
        'currency_id'        => $this->currency->id,
        'default_account_id' => $this->expenseAccount->id,
    ]);

    // Setup Customer and Vendor Partners
    $this->customer = Partner::factory()->create([
        'name'          => 'Nestle Pakistan',
        'company_id'    => $this->company->id,
        'customer_rank' => 1,
    ]);

    $this->vendor = Partner::factory()->create([
        'name'          => 'Shell Petroleum',
        'company_id'    => $this->company->id,
        'supplier_rank' => 1,
    ]);
});

it('verifies Customer Invoice resolves to Customer Invoices folder and posts cleanly to General Ledger and P&L', function () {
    // 1. Create a Customer Invoice for 50,000 PKR
    $invoice = Move::factory()->create([
        'name'         => 'INV/2026/10/0001',
        'move_type'    => MoveType::OUT_INVOICE,
        'company_id'   => $this->company->id,
        'partner_id'   => $this->customer->id,
        'journal_id'   => $this->salesJournal->id,
        'currency_id'  => $this->currency->id,
        'invoice_date' => '2026-10-05',
        'date'         => '2026-10-05',
        'state'        => MoveState::DRAFT,
    ]);

    // Line 1: Revenue (Credit) 50,000
    MoveLine::factory()->create([
        'move_id'      => $invoice->id,
        'company_id'   => $this->company->id,
        'partner_id'   => $this->customer->id,
        'account_id'   => $this->incomeAccount->id,
        'currency_id'  => $this->currency->id,
        'display_type' => DisplayType::PRODUCT,
        'quantity'     => 1,
        'price_unit'   => 50000,
        'credit'       => 50000,
        'debit'        => 0,
        'name'         => 'Logistics service invoice',
    ]);

    // Line 2: Accounts Receivable (Debit) 50,000
    MoveLine::factory()->create([
        'move_id'      => $invoice->id,
        'company_id'   => $this->company->id,
        'partner_id'   => $this->customer->id,
        'account_id'   => $this->receivableAccount->id,
        'currency_id'  => $this->currency->id,
        'display_type' => DisplayType::PAYMENT_TERM,
        'quantity'     => 1,
        'price_unit'   => 50000,
        'debit'        => 50000,
        'credit'       => 0,
        'name'         => 'Customer receivable',
    ]);

    // 2. Attach supporting document to the invoice
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
        'title'         => 'Invoice INV-2026-10-0001.pdf',
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingInvoice::class,
        'attachable_id'   => $invoice->id,
    ]);

    // 3. Verify Drive folder destination resolves to Customer Invoices / 2026 / 10-October
    $path = $this->resolver->resolve($document);
    expect($path)->toBe([
        'Aureus',
        "Truck It In ({$this->company->id})",
        'Customer Invoices',
        '2026',
        '10-October',
    ]);

    // 4. Confirm / Post the invoice to the General Ledger
    $postedInvoice = AccountFacade::confirmMove($invoice->fresh('lines'));
    expect($postedInvoice->state)->toBe(MoveState::POSTED);

    // 5. Verify General Ledger debits & credits balance perfectly
    $totalDebit = $postedInvoice->lines->sum('debit');
    $totalCredit = $postedInvoice->lines->sum('credit');
    expect($totalDebit)->toEqual(50000.0)
        ->and($totalCredit)->toEqual(50000.0);

    // 6. Verify Profit & Loss reflection: Income is recognized in posted ledger lines
    $postedIncome = DB::table('accounts_account_move_lines as l')
        ->join('accounts_account_moves as m', 'm.id', '=', 'l.move_id')
        ->where('m.company_id', $this->company->id)
        ->where('m.state', MoveState::POSTED)
        ->where('l.account_id', $this->incomeAccount->id)
        ->sum('l.credit');

    expect((float) $postedIncome)->toBe(50000.0);
});

it('verifies Vendor Bill resolves to Vendor Bills folder and posts cleanly to General Ledger and P&L expenses', function () {
    // 1. Create a Vendor Bill for 18,000 PKR
    $bill = Move::factory()->create([
        'name'         => 'BILL/2026/10/0045',
        'move_type'    => MoveType::IN_INVOICE,
        'company_id'   => $this->company->id,
        'partner_id'   => $this->vendor->id,
        'journal_id'   => $this->purchaseJournal->id,
        'currency_id'  => $this->currency->id,
        'invoice_date' => '2026-10-05',
        'date'         => '2026-10-05',
        'state'        => MoveState::DRAFT,
    ]);

    // Line 1: Expense (Debit) 18,000
    MoveLine::factory()->create([
        'move_id'      => $bill->id,
        'company_id'   => $this->company->id,
        'partner_id'   => $this->vendor->id,
        'account_id'   => $this->expenseAccount->id,
        'currency_id'  => $this->currency->id,
        'display_type' => DisplayType::PRODUCT,
        'quantity'     => 1,
        'price_unit'   => 18000,
        'debit'        => 18000,
        'credit'       => 0,
        'name'         => 'Diesel fuel expense',
    ]);

    // Line 2: Accounts Payable (Credit) 18,000
    MoveLine::factory()->create([
        'move_id'      => $bill->id,
        'company_id'   => $this->company->id,
        'partner_id'   => $this->vendor->id,
        'account_id'   => $this->payableAccount->id,
        'currency_id'  => $this->currency->id,
        'display_type' => DisplayType::PAYMENT_TERM,
        'quantity'     => 1,
        'price_unit'   => 18000,
        'credit'       => 18000,
        'debit'        => 0,
        'name'         => 'Supplier payable',
    ]);

    // 2. Attach supporting vendor bill document
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Bill,
        'title'         => 'Shell Fuel Invoice.pdf',
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingBill::class,
        'attachable_id'   => $bill->id,
    ]);

    // 3. Verify Drive folder destination resolves to Vendor Bills / 2026 / 10-October
    $path = $this->resolver->resolve($document);
    expect($path)->toBe([
        'Aureus',
        "Truck It In ({$this->company->id})",
        'Vendor Bills',
        '2026',
        '10-October',
    ]);

    // 4. Confirm / Post the bill to the General Ledger
    $postedBill = AccountFacade::confirmMove($bill->fresh('lines'));
    expect($postedBill->state)->toBe(MoveState::POSTED);

    // 5. Verify General Ledger debits & credits balance
    $totalDebit = $postedBill->lines->sum('debit');
    $totalCredit = $postedBill->lines->sum('credit');
    expect($totalDebit)->toEqual(18000.0)
        ->and($totalCredit)->toEqual(18000.0);

    // 6. Verify Profit & Loss reflection: Expense is recognized in posted ledger lines
    $postedExpense = DB::table('accounts_account_move_lines as l')
        ->join('accounts_account_moves as m', 'm.id', '=', 'l.move_id')
        ->where('m.company_id', $this->company->id)
        ->where('m.state', MoveState::POSTED)
        ->where('l.account_id', $this->expenseAccount->id)
        ->sum('l.debit');

    expect((float) $postedExpense)->toBe(18000.0);
});

it('ensures Customer Invoice and Vendor Bill documents remain strictly isolated during retrieval', function () {
    $invoice = Move::factory()->create([
        'name'        => 'INV/2026/10/0002',
        'move_type'   => MoveType::OUT_INVOICE,
        'company_id'  => $this->company->id,
        'currency_id' => $this->currency->id,
    ]);

    $bill = Move::factory()->create([
        'name'        => 'BILL/2026/10/0002',
        'move_type'   => MoveType::IN_INVOICE,
        'company_id'  => $this->company->id,
        'currency_id' => $this->currency->id,
    ]);

    $invoiceDoc = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
        'title'         => 'Customer Sales Inv.pdf',
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $invoiceDoc->id,
        'attachable_type' => $invoice->getMorphClass(),
        'attachable_id'   => $invoice->id,
    ]);

    $billDoc = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Bill,
        'title'         => 'Vendor Supplier Bill.pdf',
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $billDoc->id,
        'attachable_type' => $bill->getMorphClass(),
        'attachable_id'   => $bill->id,
    ]);

    // When retrieving from the Invoice: only invoiceDoc is attached
    $retrievedInvoiceDocs = $invoice->documentAttachments()->with('document')->get();
    expect($retrievedInvoiceDocs)->toHaveCount(1)
        ->and($retrievedInvoiceDocs->first()->document->document_type)->toBe(DocumentType::Invoice)
        ->and($retrievedInvoiceDocs->first()->document->title)->toBe('Customer Sales Inv.pdf');

    // When retrieving from the Bill: only billDoc is attached
    $retrievedBillDocs = $bill->documentAttachments()->with('document')->get();
    expect($retrievedBillDocs)->toHaveCount(1)
        ->and($retrievedBillDocs->first()->document->document_type)->toBe(DocumentType::Bill)
        ->and($retrievedBillDocs->first()->document->title)->toBe('Vendor Supplier Bill.pdf');
});
