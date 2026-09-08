<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Staff>
 */
class StaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'name' => fake()->firstName(),
            'username' => fake()->unique()->userName(),
            'login' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'telegram_chat_id' => null,
            'token' => null,
            'status' => 'active',
            'lavozim' => fake()->jobTitle(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => 'inactive',
        ]);
    }

    public function withTelegram(): static
    {
        return $this->state(fn () => [
            'telegram_chat_id' => fake()->unique()->numerify('###########'),
        ]);
    }
}
