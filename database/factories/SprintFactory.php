<?php

namespace Database\Factories;

use App\Enums\SprintStatus;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sprint>
 */
class SprintFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-7 days', '+7 days');
        $endDate = (clone $startDate);
        $endDate->modify('+' . fake()->numberBetween(7, 30) . ' days');

        return [
            'name' => 'Sprint ' . fake()->unique()->numberBetween(1, 9999),

            'description' => fake()->optional()->paragraph(),

            'start_date' => $startDate,
            'end_date' => $endDate,

            'status' => SprintStatus::PLANNED->value,

            'created_by' => Staff::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => SprintStatus::ACTIVE->value,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SprintStatus::COMPLETED->value,
        ]);
    }
}
