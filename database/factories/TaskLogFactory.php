<?php

namespace Database\Factories;

use App\Enums\TaskLogEventType;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskLog>
 */
class TaskLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'actor_id' => Staff::factory(),

            'event_type' => TaskLogEventType::CREATED->value,

            'from_status' => null,
            'to_status' => TaskStatus::CREATED->value,

            'from_assignee_id' => null,
            'to_assignee_id' => null,

            'message' => null,

            'metadata' => null,
        ];
    }

    public function assigned(Staff $assignee): static
    {
        return $this->state(fn () => [
            'event_type' => TaskLogEventType::ASSIGNED->value,
            'to_assignee_id' => $assignee->id,
            'to_status' => TaskStatus::ASSIGNED->value,
        ]);
    }

    public function reassigned(
        Staff $oldAssignee,
        Staff $newAssignee,
    ): static {
        return $this->state(fn () => [
            'event_type' => TaskLogEventType::REASSIGNED->value,
            'from_assignee_id' => $oldAssignee->id,
            'to_assignee_id' => $newAssignee->id,
        ]);
    }

    public function statusChanged(
        TaskStatus $from,
        TaskStatus $to,
    ): static {
        return $this->state(fn () => [
            'event_type' => TaskLogEventType::STATUS_CHANGED->value,
            'from_status' => $from->value,
            'to_status' => $to->value,
        ]);
    }

    public function accepted(Staff $staff): static
    {
        return $this->state(fn () => [
            'actor_id' => $staff->id,
            'event_type' => TaskLogEventType::ACCEPTED->value,
            'to_assignee_id' => $staff->id,
            'to_status' => TaskStatus::ACCEPTED->value,
        ]);
    }
}
