<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\EmployeeWorkLocationAssignment;

class EmployeeWorkLocationAssignmentFactory extends Factory
{
    protected $model = EmployeeWorkLocationAssignment::class;

    /**
     * company_id, employee_id and work_location_id must be supplied by the
     * caller: the model's saving hook requires all three to share one company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'valid_from'  => null,
            'valid_until' => null,
            'reason'      => fake()->sentence(3),
        ];
    }

    /** An assignment valid today covering recent days. */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'valid_from'  => now()->subDays(5)->toDateString(),
            'valid_until' => now()->addDays(5)->toDateString(),
        ]);
    }

    /** An assignment to a geofenced location active today. */
    public function geofenced(): static
    {
        return $this->active();
    }
}
