<?php

/**
 * Regression coverage for the offset account chosen when a bank statement line
 * is matched to an already-registered payment.
 *
 * Registering a payment posts Dr Outstanding Receipts / Cr Receivable, so the
 * receivable is already settled by the time the money shows up on the bank
 * statement. The bank line must therefore clear the *outstanding* account —
 * picking the payment's destination (the receivable) instead would credit the
 * receivable a second time and drive it negative.
 */

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function paymentOffsetFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);

    $makeAccount = function (string $code, AccountType $type) use ($company, $currency, $user): Account {
        $account = Account::factory()->create([
            'code'        => $code.$company->id, 'name' => $code, 'account_type' => $type,
            'currency_id' => $currency->id, 'creator_id' => $user->id, 'reconcile' => true,
            'is_group'    => false, 'deprecated' => false,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };

    $bank = $makeAccount('BANK-', AccountType::ASSET_CASH);
    $receivable = $makeAccount('AR-', AccountType::ASSET_RECEIVABLE);
    $outstanding = $makeAccount('OUTSTANDING-', AccountType::ASSET_CURRENT);

    $bankJournal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $bank->id, 'code' => 'BNK'.$company->id, 'name' => 'Bank', 'type' => JournalType::BANK,
    ]);

    foreach (Journal::getDefaultInboundPaymentMethodLines() as $data) {
        PaymentMethodLine::query()->create(array_merge($data, ['journal_id' => $bankJournal->id]));
    }

    $paymentMethodLine = PaymentMethodLine::query()->where('journal_id', $bankJournal->id)->firstOrFail();

    return compact('currency', 'company', 'user', 'bank', 'receivable', 'outstanding', 'bankJournal', 'paymentMethodLine');
}

function paymentOffsetMapping(array $fixture, string $amount, string $reference): BankTransactionMapping
{
    $statement = BankStatement::query()->create([
        'company_id'              => $fixture['company']->id, 'journal_id' => $fixture['bankJournal']->id,
        'currency_id'             => $fixture['currency']->id, 'company_currency_id' => $fixture['currency']->id,
        'bank_gl_account_id'      => $fixture['bank']->id, 'name' => 'Payment receipt', 'reference' => 'BANK-PAY-001',
        'date'                    => '2026-09-15', 'statement_start_date' => '2026-09-15', 'statement_end_date' => '2026-09-15',
        'opening_balance'         => 0, 'total_debits' => 0, 'total_credits' => $amount, 'closing_balance' => $amount,
        'balance_start'           => 0, 'balance_end' => $amount, 'balance_end_real' => $amount,
        'company_opening_balance' => 0, 'company_total_debits' => 0, 'company_total_credits' => $amount,
        'company_closing_balance' => $amount, 'conversion_status' => ConversionStatus::Complete,
        'bank_name'               => 'Offset Bank', 'bank_account_number' => 'BANK-PAY-001', 'account_title' => 'Operating',
        'original_filename'       => 'bank.csv', 'file_hash' => hash('sha256', 'offset'.$amount.$fixture['company']->id),
        'parser'                  => 'test', 'import_status' => BankImportStatus::Validated,
    ]);

    $line = BankStatementLine::query()->create([
        'journal_id'              => $fixture['bankJournal']->id, 'company_id' => $fixture['company']->id,
        'statement_id'            => $statement->id, 'currency_id' => $fixture['currency']->id,
        'original_currency_id'    => $fixture['currency']->id, 'company_currency_id' => $fixture['currency']->id,
        'transaction_date'        => '2026-09-15', 'value_date' => '2026-09-15',
        'description'             => 'Customer transfer received', 'reference' => $reference,
        'debit'                   => 0, 'credit' => $amount, 'original_debit' => 0, 'original_credit' => $amount,
        'original_signed_amount'  => $amount, 'company_debit' => 0, 'company_credit' => $amount,
        'company_signed_amount'   => $amount, 'amount' => $amount, 'amount_currency' => $amount,
        'amount_residual'         => $amount, 'exchange_rate' => 1, 'rate_date' => '2026-09-15',
        'rate_source'             => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'transaction_fingerprint' => hash('sha256', $reference.$amount.$fixture['company']->id),
        'import_status'           => BankImportStatus::Validated,
    ]);

    return BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id, 'statement_line_id' => $line->id,
        'bank_gl_account_id'  => $fixture['bank']->id, 'original_currency_id' => $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id, 'exchange_rate' => 1, 'rate_date' => '2026-09-15',
        'rate_source'         => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped, 'posting_status' => BankPostingStatus::NotPosted,
    ]);
}

it('clears the outstanding account, not the receivable, when a bank line matches a registered payment', function (): void {
    $fixture = paymentOffsetFixture();

    $payment = Payment::query()->create([
        'company_id'             => $fixture['company']->id,
        'journal_id'             => $fixture['bankJournal']->id,
        'payment_method_line_id' => $fixture['paymentMethodLine']->id,
        'currency_id'            => $fixture['currency']->id,
        'payment_type'           => PaymentType::RECEIVE,
        'partner_type'           => 'customer',
        'state'                  => 'in_process',
        'date'                   => '2026-09-15',
        'amount'                 => 500,
        'payment_reference'      => 'PAY-REF-9001',
        'outstanding_account_id' => $fixture['outstanding']->id,
        'destination_account_id' => $fixture['receivable']->id,
    ]);

    $mapping = paymentOffsetMapping($fixture, '500.0000', 'PAY-REF-9001');

    $result = app(BankMatchingPriorityService::class)->run($fixture['company']->id);

    $mapping->refresh();

    expect($result['payments'])->toBe(1)
        ->and($mapping->match_type)->toBe('payment')
        ->and($mapping->matched_reference)->toBe('PAY-REF-9001')
        ->and($mapping->review_status)->toBe(BankReviewStatus::Suggested);

    // The whole point: the bank line must clear the outstanding/clearing
    // account. Crediting the receivable (the payment's destination) would
    // double-settle it, since registering the payment already cleared it.
    expect($mapping->offset_account_id)->toBe($fixture['outstanding']->id)
        ->and($mapping->offset_account_id)->not->toBe($fixture['receivable']->id);

    expect($payment->fresh()->outstanding_account_id)->toBe($fixture['outstanding']->id);
});
