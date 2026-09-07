<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'company_id'         => Company::factory(),
            'source_currency_id' => Currency::factory(),
            'target_currency_id' => Currency::factory(),
            'effective_date'     => fake()->date(),
            'rate'               => '1.000000',
            'rate_type'          => ExchangeRateType::Transaction,
            'source'             => ExchangeRateSource::Manual,
            'approval_status'    => ExchangeRateApprovalStatus::Draft,
            'provider'           => null,
            'notes'              => null,
            'created_by'         => User::query()->value('id') ?? User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_status' => ExchangeRateApprovalStatus::Approved,
            'approved_by'     => User::query()->value('id') ?? User::factory(),
            'approved_at'     => now(),
        ]);
    }
}
