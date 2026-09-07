<?php

namespace Webkul\Account\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * @extends Factory<\App\Models\BankStatementLine>
 */
class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort'                => 0,
            'journal_id'          => Journal::factory(),
            'company_id'          => Company::factory(),
            'statement_id'        => BankStatement::factory(),
            'partner_id'          => null,
            'currency_id'         => Currency::factory(),
            'foreign_currency_id' => null,
            'creator_id'          => User::query()->value('id') ?? User::factory(),
            'account_number'      => fake()->optional()->numerify('############'),
            'partner_name'        => fake()->optional()->company(),
            'transaction_type'    => null,
            'payment_reference'   => fake()->optional()->bothify('PAY-####'),
            'internal_index'      => null,
            'transaction_details' => null,
            'amount'              => fake()->randomFloat(2, -1000, 1000),
            'amount_currency'     => null,
            'is_reconciled'       => false,
            'amount_residual'     => null,
        ];
    }

    public function withPartner(): static
    {
        return $this->state(fn (array $attributes) => [
            'partner_id' => Partner::query()->value('id') ?? Partner::factory(),
        ]);
    }

    public function reconciled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_reconciled'   => true,
            'amount_residual' => 0,
        ]);
    }

    public function accountingModule(array $overrides = []): static
    {
        return $this->state(function (array $attributes) use ($overrides) {
            $currencyId = $overrides['currency_id'] ?? $attributes['currency_id'];
            $debit = $overrides['debit'] ?? ($overrides['original_debit'] ?? 0);
            $credit = $overrides['credit'] ?? ($overrides['original_credit'] ?? 0);
            $signedAmount = $credit > 0 ? (string) $credit : '-'.(string) $debit;
            $date = $overrides['date'] ?? now()->toDateString();

            return array_merge([
                'transaction_date'        => $date,
                'value_date'              => $date,
                'description'             => fake()->sentence(),
                'reference'               => fake()->bothify('TXN-####'),
                'original_currency_id'    => $currencyId,
                'company_currency_id'     => $currencyId,
                'debit'                   => $debit,
                'credit'                  => $credit,
                'original_debit'          => $debit,
                'original_credit'         => $credit,
                'original_signed_amount'  => $signedAmount,
                'company_debit'           => $debit,
                'company_credit'          => $credit,
                'company_signed_amount'   => $signedAmount,
                'running_balance'         => 10000,
                'import_status'           => 'validated',
                'conversion_status'       => 'complete',
                'exchange_rate'           => 1,
                'rate_date'               => $date,
                'rate_source'             => 'identity',
                'rate_type'               => 'transaction',
                'transaction_fingerprint' => hash('sha256', fake()->uuid()),
            ], $overrides);
        });
    }
}
