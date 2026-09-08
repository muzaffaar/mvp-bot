<?php

namespace App\Services\Task;

use App\Enums\TaskAssignmentType;
use App\Enums\TaskLogEventType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    /**
     * Create a new task.
     *
     * Business rules:
     *
     * DIRECT:
     * - assignee is required
     * - initial status = ASSIGNED
     *
     * GROUP:
     * - assignee must be null
     * - task is open for acceptance
     * - initial status = AWAITING_ACCEPTANCE
     *
     * No Group model is involved.
     */
    public function create(
        array $data,
        Staff $actor,
    ): Task {
        return DB::transaction(function () use ($data, $actor) {
            $assignmentType = $data['assignment_type']
                ?? TaskAssignmentType::DIRECT;

            if (is_string($assignmentType)) {
                $assignmentType = TaskAssignmentType::from(
                    $assignmentType
                );
            }

            $assignee = null;

            if ($assignmentType === TaskAssignmentType::DIRECT) {
                if (empty($data['assignee_id'])) {
                    throw ValidationException::withMessages([
                        'assignee_id' =>
                            'Direct task must have an assignee.',
                    ]);
                }

                $assignee = Staff::query()->find(
                    (int) $data['assignee_id']
                );

                if (! $assignee) {
                    throw ValidationException::withMessages([
                        'assignee_id' =>
                            'Selected staff member does not exist.',
                    ]);
                }
            }

            if (
                $assignmentType === TaskAssignmentType::GROUP
                && ! empty($data['assignee_id'])
            ) {
                throw ValidationException::withMessages([
                    'assignee_id' =>
                        'Open task cannot have an assignee.',
                ]);
            }

            /*
             * Direct tasks:
             *
             *   ASSIGNED
             *
             * Open tasks:
             *
             *   ASSIGNED
             *
             * The Telegram chat itself determines who can receive/
             * accept an open task. TaskService does not resolve groups.
             */
            $status = match ($assignmentType) {
                TaskAssignmentType::DIRECT =>
                    TaskStatus::ASSIGNED,

                TaskAssignmentType::GROUP =>
                    TaskStatus::ASSIGNED,
            };

            $task = Task::query()->create([
                'task_number' => $this->generateTaskNumber(),

                'status' => $status,

                'title' => $data['title'],

                'description' =>
                    $data['description'] ?? null,

                /*
                 * group_id intentionally omitted.
                 *
                 * Telegram chat/group context is not a Task domain
                 * dependency anymore.
                 */

                'author_id' =>
                    $data['author_id'] ?? $actor->id,

                'assignor_id' =>
                    $data['assignor_id'] ?? $actor->id,

                'assignee_id' =>
                    $assignee?->id,

                'assignment_type' =>
                    $assignmentType,

                'priority' =>
                    $data['priority'] ?? TaskPriority::NORMAL,

                'deadline' =>
                    $data['deadline'] ?? null,

                'source_type' =>
                    $data['source_type'] ?? null,

                'source_id' =>
                    $data['source_id'] ?? null,

                'source_message_id' =>
                    $data['source_message_id'] ?? null,

                'source_url' =>
                    $data['source_url'] ?? null,

                'ajralish_aniqligi' =>
                    $data['ajralish_aniqligi'] ?? null,

                'metadata' =>
                    $data['metadata'] ?? null,
            ]);

            /*
             * Created event.
             */
            $this->createLog(
                task: $task,
                actor: $actor,
                eventType: TaskLogEventType::CREATED,
                toStatus: $status,
                toAssigneeId: $assignee?->id,
                message: $assignmentType === TaskAssignmentType::GROUP
                    ? 'Task opened for acceptance.'
                    : 'Task created and assigned.',
            );

            /*
             * Assignment/opening audit event.
             */
            if ($assignmentType === TaskAssignmentType::GROUP) {
                /*
                 * Keep this event only if the enum is still used elsewhere.
                 * It no longer contains any Group information.
                 */
                $this->createLog(
                    task: $task,
                    actor: $actor,
                    eventType: TaskLogEventType::PUBLISHED_TO_GROUP,
                    toStatus: TaskStatus::ASSIGNED,
                    message: 'Task opened for eligible Telegram members.',
                );
            } else {
                $this->createLog(
                    task: $task,
                    actor: $actor,
                    eventType: TaskLogEventType::ASSIGNED,
                    toStatus: TaskStatus::ASSIGNED,
                    toAssigneeId: $assignee?->id,
                    message: "Task assigned to {$assignee->full_name}.",
                );
            }

            return $task->fresh([
                'author',
                'assignor',
                'assignee',
                'logs',
            ]);
        });
    }

    /**
     * Assign a task to a staff member.
     *
     * No group membership validation is performed here.
     */
    public function assign(
        Task $task,
        Staff $assignee,
        Staff $actor,
    ): Task {
        return DB::transaction(function () use (
            $task,
            $assignee,
            $actor,
        ) {
            $task = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            if ($task->assignee_id !== null) {
                throw ValidationException::withMessages([
                    'task' =>
                        'Task is already assigned.',
                ]);
            }

            $oldStatus = $task->status;

            $task->update([
                'assignee_id' => $assignee->id,
                'assignment_type' => TaskAssignmentType::DIRECT,
                'status' => TaskStatus::ASSIGNED,
            ]);

            $this->createLog(
                task: $task,
                actor: $actor,
                eventType: TaskLogEventType::ASSIGNED,
                fromStatus: $oldStatus,
                toStatus: TaskStatus::ASSIGNED,
                toAssigneeId: $assignee->id,
                message: "Task assigned to {$assignee->full_name}.",
            );

            return $task->fresh([
                'assignee',
            ]);
        });
    }

    /**
     * Accept an open task.
     *
     * The database lock guarantees that only one staff member
     * can successfully claim the task.
     *
     * Telegram membership/authorization should be handled before
     * this method is called.
     */
    public function accept(
        Task $task,
        Staff $staff,
    ): Task {
        return DB::transaction(function () use (
            $task,
            $staff,
        ) {
            $task = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            if (! $task->isOpenForGroupAcceptance()) {
                throw ValidationException::withMessages([
                    'task' =>
                        'This task is not available for acceptance.',
                ]);
            }

            $task->update([
                'assignee_id' => $staff->id,
                'assignment_type' => TaskAssignmentType::DIRECT,
                'status' => TaskStatus::ACCEPTED,
            ]);

            $this->createLog(
                task: $task,
                actor: $staff,
                eventType: TaskLogEventType::ACCEPTED,
                fromStatus: TaskStatus::AWAITING_ACCEPTANCE,
                toStatus: TaskStatus::ACCEPTED,
                fromAssigneeId: null,
                toAssigneeId: $staff->id,
                message: "{$staff->full_name} accepted the task.",
            );

            return $task->fresh([
                'assignee',
            ]);
        });
    }

    /**
     * Reassign a task.
     *
     * No group membership validation is performed.
     */
    public function reassign(
        Task $task,
        Staff $newAssignee,
        Staff $actor,
    ): Task {
        return DB::transaction(function () use (
            $task,
            $newAssignee,
            $actor,
        ) {
            $task = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            $oldAssigneeId = $task->assignee_id;

            if ($oldAssigneeId === $newAssignee->id) {
                throw ValidationException::withMessages([
                    'assignee_id' =>
                        'Task is already assigned to this staff member.',
                ]);
            }

            $task->update([
                'assignee_id' => $newAssignee->id,
                'assignment_type' => TaskAssignmentType::DIRECT,
            ]);

            $this->createLog(
                task: $task,
                actor: $actor,
                eventType: TaskLogEventType::REASSIGNED,
                fromStatus: $task->status,
                toStatus: $task->status,
                fromAssigneeId: $oldAssigneeId,
                toAssigneeId: $newAssignee->id,
                message: "Task reassigned to {$newAssignee->full_name}.",
            );

            return $task->fresh([
                'assignee',
            ]);
        });
    }

    /**
     * Change task status.
     */
    public function changeStatus(
        Task $task,
        TaskStatus $newStatus,
        Staff $actor,
        ?string $message = null,
    ): Task {
        return DB::transaction(function () use (
            $task,
            $newStatus,
            $actor,
            $message,
        ) {
            $task = Task::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            $oldStatus = $task->status;

            if ($oldStatus === $newStatus) {
                throw ValidationException::withMessages([
                    'status' =>
                        'Task is already in this status.',
                ]);
            }

            $this->validateStatusTransition(
                $oldStatus,
                $newStatus
            );

            $updates = [
                'status' => $newStatus,
            ];

            if (
                $newStatus === TaskStatus::IN_PROGRESS
                && $task->started_at === null
            ) {
                $updates['started_at'] = now();
            }

            if ($newStatus === TaskStatus::CLOSED) {
                $updates['completed_at'] ??= now();
                $updates['closed_at'] = now();
            }

            $task->update($updates);

            $eventType = match ($newStatus) {
                TaskStatus::IN_PROGRESS =>
                    TaskLogEventType::STARTED,

                TaskStatus::CLOSED =>
                    TaskLogEventType::CLOSED,

                TaskStatus::CANCELLED =>
                    TaskLogEventType::CANCELLED,

                default =>
                    TaskLogEventType::STATUS_CHANGED,
            };

            $this->createLog(
                task: $task,
                actor: $actor,
                eventType: $eventType,
                fromStatus: $oldStatus,
                toStatus: $newStatus,
                message: $message,
            );

            return $task->fresh();
        });
    }

    /**
     * Generate human-readable task number.
     *
     * Example:
     * TASK-2026-000001
     */
    private function generateTaskNumber(): string
    {
        $year = now()->year;

        $lastTask = Task::query()
            ->where(
                'task_number',
                'like',
                "TASK-{$year}-%"
            )
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastTask
            ? ((int) substr($lastTask->task_number, -6)) + 1
            : 1;

        return sprintf(
            'TASK-%d-%06d',
            $year,
            $nextNumber
        );
    }

    /**
     * Create immutable audit log.
     */
    private function createLog(
        Task $task,
        ?Staff $actor,
        TaskLogEventType $eventType,
        ?TaskStatus $fromStatus = null,
        ?TaskStatus $toStatus = null,
        ?int $fromAssigneeId = null,
        ?int $toAssigneeId = null,
        ?string $message = null,
        ?array $metadata = null,
    ): TaskLog {
        return TaskLog::query()->create([
            'task_id' => $task->id,
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_assignee_id' => $fromAssigneeId,
            'to_assignee_id' => $toAssigneeId,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Validate lifecycle transition.
     */
    private function validateStatusTransition(
        TaskStatus $from,
        TaskStatus $to,
    ): void {
        $allowed = match ($from) {
            TaskStatus::CREATED => [
                TaskStatus::ASSIGNED,
                TaskStatus::AWAITING_ACCEPTANCE,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::ASSIGNED => [
                TaskStatus::IN_PROGRESS,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::AWAITING_ACCEPTANCE => [
                TaskStatus::ACCEPTED,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::ACCEPTED => [
                TaskStatus::IN_PROGRESS,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::IN_PROGRESS => [
                TaskStatus::CLOSED,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::CLOSED => [],

            TaskStatus::CANCELLED => [
                TaskStatus::CREATED,
            ],
        };

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' =>
                    "Invalid task status transition: "
                    . "{$from->value} → {$to->value}.",
            ]);
        }
    }
}
