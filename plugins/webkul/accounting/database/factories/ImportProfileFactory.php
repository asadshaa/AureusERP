<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Enums\ImportFailurePolicy;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<ImportProfile>
 */
class ImportProfileFactory extends Factory
{
    protected $model = ImportProfile::class;

    public function definition(): array
    {
        return [
            'company_id'     => Company::factory(),
            'owner_id'       => User::query()->value('id') ?? User::factory(),
            'name'           => ucwords(fake()->words(3, true)),
            'entity_type'    => 'bank_statement',
            'file_type'      => 'csv',
            'sheet_name'     => null,
            'header_row'     => 1,
            'data_start_row' => 2,
            'skip_rows'      => 0,
            'blank_row_rule' => 'skip',
            'failure_policy' => ImportFailurePolicy::RejectFailedRows->value,
            'stop_rule'      => null,
            'delimiter'      => ',',
            'encoding'       => 'UTF-8',
            'version'        => 1,
            'is_active'      => true,
            'activated_at'   => now(),
        ];
    }

    public function openingBalance(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => 'opening_balance',
        ]);
    }

    public function bankStatement(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => 'bank_statement',
        ]);
    }

    public function rejectFile(): static
    {
        return $this->state(fn (array $attributes) => [
            'failure_policy' => ImportFailurePolicy::RejectFile->value,
        ]);
    }

    public function rejectRows(): static
    {
        return $this->state(fn (array $attributes) => [
            'failure_policy' => ImportFailurePolicy::RejectFailedRows->value,
        ]);
    }

    public function flagReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'failure_policy' => ImportFailurePolicy::NeedsReview->value,
        ]);
    }

    public function warnContinue(): static
    {
        return $this->state(fn (array $attributes) => [
            'failure_policy' => ImportFailurePolicy::WarnContinue->value,
        ]);
    }
}
