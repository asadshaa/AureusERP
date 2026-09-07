<?php

namespace Webkul\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ImportProfileMapping;

/**
 * @extends Factory<ImportProfileMapping>
 */
class ImportProfileMappingFactory extends Factory
{
    protected $model = ImportProfileMapping::class;

    public function definition(): array
    {
        return [
            'profile_id'       => ImportProfile::factory(),
            'position'         => fake()->numberBetween(1, 20),
            'source_header'    => fake()->word(),
            'source_position'  => null,
            'source_aliases'   => [],
            'target_field'     => 'date',
            'transformations'  => [],
            'validation_rules' => [],
            'is_required'      => false,
        ];
    }
}
