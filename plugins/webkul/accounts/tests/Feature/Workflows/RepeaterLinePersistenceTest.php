<?php

/**
 * Regression coverage for the "invoice product line silently vanishes /
 * Tax Excluded and Total stay 0.00" bug.
 *
 * IMPORTANT: unlike AccountHelper::productLine(), which explicitly sets
 * `display_type` and `account_id` on the factory-created MoveLine (and so
 * never exercises the bug), these tests create lines the way Filament's
 * Repeater actually does it in production: via the scoped `invoiceLines()`
 * relationship, passing only the fields a user fills in on the form
 * (product_id, quantity, price_unit, discount, currency_id). `display_type`
 * and `account_id` are left null so the MoveLine::saving() boot hook has to
 * compute them itself -- which is exactly the code path that was broken.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Journal;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    AccountHelper::actingAsAdmin();

    $this->partner = AccountHelper::partner();
});

function createRepeaterProductLine($move, $product, $qty, $priceUnit, $discount = 0)
{
    // Mirrors exactly what vendor/filament/forms/src/Components/Repeater.php's
    // saveToRelationship() sends: $relationship->save($record) where
    // $relationship = $move->invoiceLines() and $record only carries the
    // fields the form schema exposes -- no display_type, no account_id.
    return $move->invoiceLines()->create([
        'product_id' => $product->id,
        'uom_id'     => $product->uom_id,
        'quantity'   => $qty,
        'price_unit' => $priceUnit,
        'discount'   => $discount,
    ]);
}

it('persists a single customer invoice line created the way the repeater creates it', function () {
    $product = AccountHelper::product([
        'property_account_income_id' => AccountHelper::account('income')->id,
    ]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);

    createRepeaterProductLine($invoice, $product, qty: 1, priceUnit: 875000);

    AccountHelper::compute($invoice);

    $invoice->refresh();

    expect($invoice->invoiceLines()->count())->toBe(1)
        ->and((float) $invoice->amount_untaxed)->toBe(875000.0)
        ->and((float) $invoice->amount_total)->toBe(875000.0);

    $line = $invoice->invoiceLines()->first();

    expect($line->display_type)->toBe(DisplayType::PRODUCT)
        ->and($line->account_id)->toBe($product->property_account_income_id)
        ->and($line->account->account_type)->toBe(AccountType::INCOME);
});

it('persists multiple customer invoice lines created the way the repeater creates them', function () {
    $income = AccountHelper::account('income');
    $productA = AccountHelper::product(['property_account_income_id' => $income->id]);
    $productB = AccountHelper::product(['property_account_income_id' => $income->id]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);

    createRepeaterProductLine($invoice, $productA, qty: 2, priceUnit: 100);
    createRepeaterProductLine($invoice, $productB, qty: 1, priceUnit: 50);

    AccountHelper::compute($invoice);

    $invoice->refresh();

    expect($invoice->invoiceLines()->count())->toBe(2)
        ->and((float) $invoice->amount_untaxed)->toBe(250.0)
        ->and((float) $invoice->amount_total)->toBe(250.0);
});

it('persists a vendor bill line created the way the repeater creates it, using the expense account', function () {
    $expense = AccountHelper::account('expense');
    $product = AccountHelper::product(['property_account_expense_id' => $expense->id]);

    $bill = AccountHelper::invoice(MoveType::IN_INVOICE, $this->partner);

    createRepeaterProductLine($bill, $product, qty: 3, priceUnit: 200);

    AccountHelper::compute($bill);

    $bill->refresh();

    expect($bill->invoiceLines()->count())->toBe(1)
        ->and((float) $bill->amount_untaxed)->toBe(600.0)
        ->and((float) $bill->amount_total)->toBe(600.0);

    $line = $bill->invoiceLines()->first();

    expect($line->display_type)->toBe(DisplayType::PRODUCT)
        ->and($line->account_id)->toBe($expense->id);
});

it('does not silently delete the line when the journal default account is Accounts Receivable, exactly like the real Customer Invoices journal', function () {
    // In production, Journal "Customer Invoices" (Sale type) has its
    // default_account_id pointing at Accounts Receivable -- this is what
    // turned the "wrong account" bug into a full "line disappears, Total
    // stays PKR 0.00" bug, as reported live in the app.
    $receivable = AccountHelper::account('receivable');

    $journal = Journal::factory()->sale()->create([
        'company_id'         => AccountHelper::company()->id,
        'currency_id'        => AccountHelper::currency()->id,
        'default_account_id' => $receivable->id,
    ]);

    $product = AccountHelper::product([
        'property_account_income_id' => AccountHelper::account('income')->id,
    ]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, $journal);

    createRepeaterProductLine($invoice, $product, qty: 1, priceUnit: 875000);

    AccountHelper::compute($invoice);

    $invoice->refresh();

    expect($invoice->invoiceLines()->count())->toBe(1)
        ->and((float) $invoice->amount_untaxed)->toBe(875000.0)
        ->and((float) $invoice->amount_total)->toBe(875000.0);
});

it('keeps the invoice balanced end to end when posted from a repeater-created line', function () {
    $product = AccountHelper::product([
        'property_account_income_id' => AccountHelper::account('income')->id,
    ]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);

    createRepeaterProductLine($invoice, $product, qty: 1, priceUnit: 875000);

    AccountHelper::compute($invoice);
    AccountHelper::post($invoice);

    $invoice->refresh();

    expect($invoice->state)->toBe(MoveState::POSTED)
        ->and((float) $invoice->amount_total)->toBe(875000.0);

    $totalDebit = $invoice->lines->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $invoice->lines->sum(fn ($l) => (float) $l->credit);

    expect($totalDebit)->toBe($totalCredit)
        ->and($totalDebit)->toBe(875000.0);
});
