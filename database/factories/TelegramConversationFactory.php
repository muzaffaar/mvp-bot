<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TelegramConversation>
 */
class TelegramConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'telegram_chat_id' => fake()->unique()->numberBetween(
                100000000,
                999999999999
            ),
            'state' => 'idle',
            'context' => [],
            'last_activity_at' => now(),
        ];
    }
}
