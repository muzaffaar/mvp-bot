<?php

namespace Database\Factories;

use App\Enums\TaskAssignmentType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Group;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_number' => 'TASK-' . fake()->unique()->numerify('####-######'),

            'status' => TaskStatus::CREATED->value,

            'title' => fake()->sentence(5),
            'description' => fake()->optional()->paragraph(),

            'author_id' => Staff::factory(),

            'assignor_id' => null,
            'assignee_id' => null,

            'assignment_type' => TaskAssignmentType::DIRECT->value,

            'priority' => TaskPriority::NORMAL->value,

            'deadline' => fake()->optional()->dateTimeBetween(
                'now',
                '+30 days'
            ),

            'source_type' => null,
            'source_id' => null,
            'source_message_id' => null,
            'source_url' => null,

            'ajralish_aniqligi' => null,

            'started_at' => null,
            'completed_at' => null,
            'closed_at' => null,
        ];
    }

    public function direct(): static
    {
        return $this->state(fn () => [
            'assignment_type' => TaskAssignmentType::DIRECT->value,
        ]);
    }

    public function groupAssignment(): static
    {
        return $this->state(fn () => [
            'assignment_type' => TaskAssignmentType::GROUP->value,
            'assignee_id' => null,
            'status' => TaskStatus::AWAITING_ACCEPTANCE->value,
        ]);
    }

    public function assigned(Staff $staff): static
    {
        return $this->state(fn () => [
            'assignee_id' => $staff->id,
            'status' => TaskStatus::ASSIGNED->value,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::ACCEPTED->value,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::CLOSED->value,
            'completed_at' => now(),
            'closed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::CANCELLED->value,
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn () => [
            'priority' => TaskPriority::HIGH->value,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => [
            'priority' => TaskPriority::URGENT->value,
        ]);
    }

    public function fromTelegram(): static
    {
        return $this->state(fn () => [
            'source_type' => 'telegram',
            'source_id' => fake()->uuid(),
            'source_message_id' => (string) fake()->numberBetween(1, 999999),
            'source_url' => 'https://t.me/example/' . fake()->numberBetween(1, 999999),
        ]);
    }

    public function aiGenerated(float $confidence = 95.00): static
    {
        return $this->state(fn () => [
            'ajralish_aniqligi' => $confidence,
        ]);
    }
}
