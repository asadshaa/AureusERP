<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Models\BusinessRule;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<BusinessRule>
 */
class BusinessRuleFactory extends Factory
{
    protected $model = BusinessRule::class;

    public function definition(): array
    {
        return [
            'company_id'      => Company::factory(),
            'name'            => ucwords(fake()->words(3, true)),
            'entity_type'     => 'bank_statement',
            'conditions'      => [],
            'actions'         => [],
            'priority'        => 1,
            'stop_processing' => false,
            'is_active'       => true,
        ];
    }
}
