<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<ManualAdjustment>
 */
class ManualAdjustmentFactory extends Factory
{
    protected $model = ManualAdjustment::class;

    public function definition(): array
    {
        return [
            'company_id'        => Company::factory(),
            'date'              => fake()->date(),
            'debit_account_id'  => Account::factory(),
            'credit_account_id' => Account::factory(),
            'amount'            => 20000.00,
            'description'       => fake()->sentence(),
            'approval_status'   => ManualAdjustmentStatus::Draft,
            'creator_id'        => User::query()->value('id') ?? User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approval_status' => ManualAdjustmentStatus::Approved,
        ]);
    }
}
