<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Models\FsTag;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<FsTag>
 */
class FsTagFactory extends Factory
{
    protected $model = FsTag::class;

    public function definition(): array
    {
        return [
            'company_id'         => Company::factory(),
            'code'               => 'FS-'.strtoupper(fake()->unique()->lexify('????')),
            'name'               => ucwords(fake()->words(2, true)),
            'account_id'         => null,
            'cash_flow_category' => null,
            'tax_treatment'      => null,
            'is_active'          => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
