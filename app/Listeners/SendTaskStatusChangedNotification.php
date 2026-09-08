<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Notifications\TaskStatusChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendTaskStatusChangedNotification implements ShouldQueue
{
    public function handle(TaskStatusChanged $event): void
    {
        Log::info('TaskStatusChanged listener started', [
            'task_id' => $event->task->id,
            'task_number' => $event->task->task_number,
            'from_status' => $event->fromStatus->value,
            'to_status' => $event->toStatus->value,
            'actor_id' => $event->actor->id,
        ]);

        $task = $event->task->fresh([
            'assignor',
            'assignee',
        ]);

        if (! $task) {
            Log::warning('Task notification: task not found');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | ACCEPTED
        |--------------------------------------------------------------------------
        |
        | Notify:
        | 1. The staff member who accepted the task
        | 2. The assignor
        |
        */
        if ($event->toStatus === TaskStatus::ACCEPTED) {
            $assignee = $task->assignee;

            if ($assignee) {
                $assignee->notify(
                    new TaskStatusChangedNotification(
                        task: $task,
                        fromStatus: $event->fromStatus,
                        toStatus: $event->toStatus,
                        actor: $event->actor,
                        message: $event->message,
                        metadata: $event->metadata,
                    )
                );
            }

            $assignor = $task->assignor;

            if (
                $assignor
                && $assignor->id !== $assignee?->id
            ) {
                $assignor->notify(
                    new TaskStatusChangedNotification(
                        task: $task,
                        fromStatus: $event->fromStatus,
                        toStatus: $event->toStatus,
                        actor: $event->actor,
                        message: $event->message,
                        metadata: $event->metadata,
                    )
                );
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | IN_PROGRESS
        |--------------------------------------------------------------------------
        |
        | The assignee pressed:
        | "▶️ Ishni boshlash"
        |
        | Now send them:
        | "✅ Bajarildi"
        |
        */
        if ($event->toStatus === TaskStatus::IN_PROGRESS) {
            $assignee = $task->assignee;

            if (! $assignee) {
                Log::warning(
                    'Task notification: assignee not found for IN_PROGRESS',
                    [
                        'task_id' => $task->id,
                        'assignee_id' => $task->assignee_id,
                    ]
                );

                return;
            }

            $assignee->notify(
                new TaskStatusChangedNotification(
                    task: $task,
                    fromStatus: $event->fromStatus,
                    toStatus: $event->toStatus,
                    actor: $event->actor,
                    message: $event->message,
                    metadata: $event->metadata,
                )
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | AWAITING_ACCEPTANCE
        |--------------------------------------------------------------------------
        |
        | The assignee pressed:
        | "✅ Bajarildi"
        |
        | Assignor must review/accept the completed task.
        |
        */
        if ($event->toStatus === TaskStatus::AWAITING_ACCEPTANCE) {
            $assignor = $task->assignor;

            if (! $assignor) {
                Log::warning(
                    'Task notification: assignor not found',
                    [
                        'task_id' => $task->id,
                        'assignor_id' => $task->assignor_id,
                    ]
                );

                return;
            }

            $assignor->notify(
                new TaskStatusChangedNotification(
                    task: $task,
                    fromStatus: $event->fromStatus,
                    toStatus: $event->toStatus,
                    actor: $event->actor,
                    message: $event->message,
                    metadata: $event->metadata,
                )
            );

            return;
        }

        Log::info('Task notification skipped', [
            'task_id' => $task->id,
            'status' => $event->toStatus->value,
        ]);

        if ($event->toStatus === TaskStatus::CLOSED) {
            $assignor = $task->assignor;

            if (! $assignor) {
                Log::warning(
                    'Task closed but assignor was not found.',
                    [
                        'task_id' => $task->id,
                    ]
                );

                return;
            }

            $assignor->notify(
                new TaskStatusChangedNotification(
                    task: $task,
                    fromStatus: $event->fromStatus,
                    toStatus: $event->toStatus,
                    actor: $event->actor,
                    message: $event->message,
                    metadata: $event->metadata,
                )
            );

            return;
        }

        if ($event->toStatus === TaskStatus::COMPLETION_APPROVED) {
            $assignor = $task->assignor;

            if (! $assignor) {
                Log::warning(
                    'Task closed but assignor was not found.',
                    [
                        'task_id' => $task->id,
                    ]
                );

                return;
            }

            $assignor->notify(
                new TaskStatusChangedNotification(
                    task: $task,
                    fromStatus: $event->fromStatus,
                    toStatus: $event->toStatus,
                    actor: $event->actor,
                    message: $event->message,
                    metadata: $event->metadata,
                )
            );

            return;
        }

        if ($event->toStatus === TaskStatus::RETURNED) {
            $assignor = $task->assignor;

            if (! $assignor) {
                Log::warning(
                    'Task closed but assignor was not found.',
                    [
                        'task_id' => $task->id,
                    ]
                );

                return;
            }

            $assignor->notify(
                new TaskStatusChangedNotification(
                    task: $task,
                    fromStatus: $event->fromStatus,
                    toStatus: $event->toStatus,
                    actor: $event->actor,
                    message: $event->message,
                    metadata: $event->metadata,
                )
            );

            return;
        }
    }
}
