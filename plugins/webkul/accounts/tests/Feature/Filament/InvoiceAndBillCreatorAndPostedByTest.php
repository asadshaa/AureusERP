<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Filament\Resources\BillResource\Pages\ListBills;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Webkul\Invoice\Models\Invoice;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');
});

it('renders creator.name and postedBy.name columns on invoice dashboard', function () {
    FilamentHelper::actingAs(['view_any_account_invoice']);

    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('state')
        ->assertCanRenderTableColumn('creator.name')
        ->assertCanRenderTableColumn('postedBy.name');
});

it('renders creator.name and postedBy.name columns on bill dashboard', function () {
    FilamentHelper::actingAs(['view_any_account_bill']);

    Livewire::test(ListBills::class)
        ->assertOk()
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('state')
        ->assertCanRenderTableColumn('creator.name')
        ->assertCanRenderTableColumn('postedBy.name');
});

it('tracks who created and who posted an invoice and clears poster when drafted', function () {
    $creator = User::factory()->create();
    $poster = User::factory()->create();

    // Act as creator to generate the invoice
    Auth::login($creator);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);
    $invoice->update(['creator_id' => $creator->id]);
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::compute($invoice);

    expect($invoice->creator_id)->toBe($creator->id)
        ->and($invoice->creator->id)->toBe($creator->id)
        ->and($invoice->posted_by_id)->toBeNull()
        ->and($invoice->posted_at)->toBeNull();

    // Act as poster to confirm/post the invoice
    Auth::login($poster);

    AccountFacade::confirmMove($invoice);

    $invoice->refresh();

    expect($invoice->state)->toBe(MoveState::POSTED)
        ->and($invoice->creator_id)->toBe($creator->id)
        ->and($invoice->creator->name)->toBe($creator->name)
        ->and($invoice->posted_by_id)->toBe($poster->id)
        ->and($invoice->postedBy->id)->toBe($poster->id)
        ->and($invoice->postedBy->name)->toBe($poster->name)
        ->and($invoice->posted_at)->not->toBeNull();

    // Resetting to draft should clear posted_by_id and posted_at
    AccountFacade::resetToDraftMove($invoice);

    $invoice->refresh();

    expect($invoice->state)->toBe(MoveState::DRAFT)
        ->and($invoice->creator_id)->toBe($creator->id)
        ->and($invoice->posted_by_id)->toBeNull()
        ->and($invoice->posted_at)->toBeNull();
});

it('tracks who created and who posted a vendor bill', function () {
    $creator = User::factory()->create();
    $poster = User::factory()->create();

    Auth::login($creator);

    $bill = AccountHelper::invoice(MoveType::IN_INVOICE);
    $bill->update(['creator_id' => $creator->id]);
    AccountHelper::productLine($bill, AccountHelper::account('expense'), qty: 1, priceUnit: 50);
    AccountHelper::compute($bill);

    expect($bill->creator_id)->toBe($creator->id)
        ->and($bill->posted_by_id)->toBeNull();

    Auth::login($poster);

    AccountFacade::confirmMove($bill);

    $bill->refresh();

    expect($bill->state)->toBe(MoveState::POSTED)
        ->and($bill->creator_id)->toBe($creator->id)
        ->and($bill->posted_by_id)->toBe($poster->id)
        ->and($bill->postedBy->name)->toBe($poster->name)
        ->and($bill->posted_at)->not->toBeNull();
});

it('allows supervisor to verify, post from table row action, and post in bulk', function () {
    $supervisor = User::factory()->create();
    $operator = User::factory()->create();

    // Operator creates draft invoice
    Auth::login($operator);
    $invoice1 = AccountHelper::invoice(MoveType::OUT_INVOICE);
    $invoice1->update(['creator_id' => $operator->id, 'checked' => false]);
    AccountHelper::productLine($invoice1, AccountHelper::account('income'), qty: 1, priceUnit: 100);
    AccountHelper::compute($invoice1);

    $invoice2 = AccountHelper::invoice(MoveType::OUT_INVOICE);
    $invoice2->update(['creator_id' => $operator->id, 'checked' => false]);
    AccountHelper::productLine($invoice2, AccountHelper::account('income'), qty: 2, priceUnit: 50);
    AccountHelper::compute($invoice2);

    // Supervisor logs in
    $supervisor = FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    // Test mark_checked action
    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->callTableAction('mark_checked', $invoice1);

    expect($invoice1->refresh()->checked)->toBeTrue();

    // Test row confirm action
    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->callTableAction('confirm', $invoice1);

    $invoice1->refresh();
    expect($invoice1->state)->toBe(MoveState::POSTED)
        ->and($invoice1->creator_id)->toBe($operator->id)
        ->and($invoice1->posted_by_id)->toBe($supervisor->id);

    // Test bulk post action on invoice2
    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->callTableBulkAction('post_selected', [$invoice2]);

    $invoice2->refresh();
    expect($invoice2->state)->toBe(MoveState::POSTED)
        ->and($invoice2->creator_id)->toBe($operator->id)
        ->and($invoice2->posted_by_id)->toBe($supervisor->id);
});

it('evaluates infolist audit status cleanly for Invoice and Move models without TypeError', function () {
    $creator = User::factory()->create(['name' => 'Operator User']);
    $poster = User::factory()->create(['name' => 'Supervisor User']);

    $move = AccountHelper::invoice(MoveType::OUT_INVOICE);
    $move->update([
        'creator_id'   => $creator->id,
        'posted_by_id' => $poster->id,
        'posted_at'    => now(),
        'state'        => MoveState::POSTED,
    ]);

    FilamentHelper::actingAs([
        'view_any_account_invoice',
        'view_account_invoice',
        'view_any_invoice_invoice',
        'view_invoice_invoice',
    ]);

    Livewire::test(ViewInvoice::class, [
        'record' => $move->id,
    ])->assertOk();

    if (class_exists(Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource\Pages\ViewInvoice::class)) {
        $invoiceModel = Invoice::find($move->id);
        expect($invoiceModel)->not->toBeNull();

        Livewire::test(Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource\Pages\ViewInvoice::class, [
            'record' => $invoiceModel->id,
        ])->assertOk();
    }
});
