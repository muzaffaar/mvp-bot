<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskComment>
 */
class TaskCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'staff_id' => Staff::factory(),
            'task_log_id' => null,
            'body' => fake()->paragraph(),
        ];
    }

    public function attachedToLog(TaskLog $log): static
    {
        return $this->state(fn () => [
            'task_id' => $log->task_id,
            'task_log_id' => $log->id,
        ]);
    }
}
