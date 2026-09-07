<?php

namespace Webkul\Account\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<\App\Models\BankStatement>
 */
class BankStatementFactory extends Factory
{
    protected $model = BankStatement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balanceStart = fake()->randomFloat(2, 0, 10000);
        $balanceEnd = $balanceStart + fake()->randomFloat(2, -1000, 1000);

        return [
            'company_id'       => Company::factory(),
            'journal_id'       => Journal::factory(),
            'creator_id'       => User::query()->value('id') ?? User::factory(),
            'name'             => fake()->words(2, true),
            'reference'        => fake()->optional()->bothify('STMT-####'),
            'first_line_index' => 0,
            'date'             => fake()->date(),
            'balance_start'    => $balanceStart,
            'balance_end'      => $balanceEnd,
            'balance_end_real' => $balanceEnd,
            'is_completed'     => false,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => true,
        ]);
    }

    public function accountingModule(array $overrides = []): static
    {
        return $this->state(function (array $attributes) use ($overrides) {
            $companyId = $overrides['company_id'] ?? $attributes['company_id'];
            $currencyId = $overrides['company_currency_id'] ?? $overrides['currency_id'] ?? 1;
            $balance = $overrides['opening_balance'] ?? 10000;
            $closing = $overrides['closing_balance'] ?? 10000;

            return array_merge([
                'currency_id'             => $currencyId,
                'company_currency_id'     => $currencyId,
                'bank_gl_account_id'      => null,
                'statement_start_date'    => $attributes['date'] ?? now()->toDateString(),
                'statement_end_date'      => $attributes['date'] ?? now()->toDateString(),
                'opening_balance'         => $balance,
                'total_debits'            => 0,
                'total_credits'           => 0,
                'closing_balance'         => $closing,
                'balance_start'           => $balance,
                'balance_end'             => $closing,
                'balance_end_real'        => $closing,
                'company_opening_balance' => $balance,
                'company_total_debits'    => 0,
                'company_total_credits'   => 0,
                'company_closing_balance' => $closing,
                'conversion_status'       => 'complete',
                'bank_name'               => fake()->company().' Bank',
                'bank_account_number'     => fake()->numerify('PK55DEMO################'),
                'account_title'           => fake()->company(),
                'original_filename'       => 'statement.csv',
                'file_hash'               => hash('sha256', fake()->uuid()),
                'parser'                  => 'hbl',
                'import_status'           => 'validated',
            ], $overrides);
        });
    }
}
