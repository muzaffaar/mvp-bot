<?php

namespace App\Services\Task;

use App\Enums\TaskLogEventType;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\Staff;
use App\Models\Task;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TaskStatusTransitionService
{
    /**
     * Accept an open Telegram task.
     *
     * Open tasks are not associated with an App\Models\Group.
     *
     * Eligibility is determined by:
     *
     *     Staff.group_chat_id === current Telegram chat ID
     *
     * The actual assignment is protected by a database row lock so
     * only one eligible staff member can win the race.
     */
    public function acceptGroupTask(
        Task $task,
        Staff $actor,
        int $chatId,
    ): Task {
        /*
         * Cheap validation before opening the transaction.
         *
         * These checks are NOT the concurrency protection.
         */
        if (
            $task->assignment_type?->value !== 'group'
            || $task->assignee_id !== null
        ) {
            throw new DomainException(
                'Bu vazifa ochiq vazifa emas.'
            );
        }

        /*
         * The task must be waiting for somebody to accept it.
         */
        if ($task->status !== TaskStatus::ASSIGNED) {
            throw new DomainException(
                'Bu vazifa allaqachon qabul qilingan yoki mavjud emas.'
            );
        }

        /*
         * Verify Telegram-chat membership before entering the
         * critical section.
         *
         * This is only a cheap preliminary check.
         *
         * The same check is repeated after acquiring the task lock.
         */
        $isGroupMember = $this->isEligibleTelegramMember(
            actor: $actor,
            chatId: $chatId,
        );

        if (! $isGroupMember) {
            throw new AuthorizationException(
                'Siz ushbu Telegram guruhining faol a’zosi emassiz.'
            );
        }

        /*
         * Capture the expected old status.
         */
        $oldStatus = $task->status;

        /*
         * =========================================================
         * CRITICAL SECTION
         * =========================================================
         *
         * Lock the exact task row.
         *
         * If two people click "Qabul qilish" at the same time,
         * PostgreSQL serializes these transactions.
         */
        $acceptedTask = DB::transaction(function () use (
            $task,
            $actor,
            $chatId,
            $oldStatus,
        ) {
            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedTask) {
                throw new DomainException(
                    'Vazifa topilmadi.'
                );
            }

            /*
             * =====================================================
             * RE-CHECK AFTER LOCK
             * =====================================================
             *
             * Another employee may have accepted the task while
             * this request was waiting for the lock.
             */
            if (
                $lockedTask->status
                !== TaskStatus::ASSIGNED
            ) {
                throw new DomainException(
                    'Bu vazifa allaqachon qabul qilingan yoki mavjud emas.'
                );
            }

            if ($lockedTask->assignee_id !== null) {
                throw new DomainException(
                    'Bu vazifa allaqachon boshqa xodim tomonidan qabul qilingan.'
                );
            }

            /*
             * Verify that the actor is still an active member of
             * the current Telegram chat.
             *
             * There is intentionally NO Task->group relationship.
             */
            if (! $this->isEligibleTelegramMember(
                actor: $actor,
                chatId: $chatId,
            )) {
                throw new AuthorizationException(
                    'Siz ushbu Telegram guruhining faol a’zosi emassiz.'
                );
            }

            /*
             * =====================================================
             * WINNER
             * =====================================================
             *
             * This actor becomes the assignee.
             */
            $lockedTask->update([
                'assignee_id' => $actor->id,
                'status' => TaskStatus::ACCEPTED,
            ]);

            /*
             * =====================================================
             * AUDIT LOG
             * =====================================================
             */
            $lockedTask->logs()->create([
                'actor_id' => $actor->id,
                'event_type' => TaskLogEventType::STATUS_CHANGED,
                'from_status' => $oldStatus,
                'to_status' => TaskStatus::ACCEPTED,
                'from_assignee_id' => null,
                'to_assignee_id' => $actor->id,
            ]);

            /*
             * Load only relationships that still exist.
             *
             * There is NO "group".
             */
            return $lockedTask->fresh([
                'assignee',
                'assignor',
                'author',
            ]);
        });

        /*
         * =========================================================
         * AFTER COMMIT
         * =========================================================
         *
         * Notifications are dispatched only after the transaction
         * successfully commits.
         */
        TaskStatusChanged::dispatch(
            task: $acceptedTask,
            fromStatus: $oldStatus,
            toStatus: TaskStatus::ACCEPTED,
            actor: $actor,
            message: null,
        );

        return $acceptedTask;
    }

    /**
     * Check whether a staff member is an active member of the
     * current Telegram chat.
     *
     * Telegram user ID:
     *     Staff.telegram_chat_id
     *
     * Telegram group/chat ID:
     *     Staff.group_chat_id
     */
    private function isEligibleTelegramMember(
        Staff $actor,
        int $chatId,
    ): bool {
        // if ($actor->status !== 'active') {
        //     return false;
        // }

        /*
         * group_chat_id identifies the Telegram group.
         *
         * telegram_chat_id identifies the Telegram user.
         */
        // return (string) $actor->group_chat_id === (string) $chatId;
        return $actor->status == 'active';
    }

    /*
    |--------------------------------------------------------------------------
    | Generic status change
    |--------------------------------------------------------------------------
    */

    public function change(
        Task $task,
        TaskStatus $newStatus,
        Staff $actor,
        ?string $message = null,
        ?array $metadata = null,
    ): Task {
        $result = DB::transaction(function () use (
            $task,
            $newStatus,
            $actor,
            $message,
            $metadata,
        ) {
            /*
             * Lock the actual database row.
             */
            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedTask) {
                throw new DomainException(
                    'Vazifa topilmadi.'
                );
            }

            /*
             * Always use the status from the locked database row.
             */
            $oldStatus = $lockedTask->status;

            /*
             * Authorization against the current locked state.
             */
            $this->authorize(
                task: $lockedTask,
                newStatus: $newStatus,
                actor: $actor,
            );

            /*
             * Validate the state transition.
             */
            $this->validateTransition(
                current: $oldStatus,
                target: $newStatus,
            );

            /*
             * Prepare timestamp changes.
             */
            $updates = [
                'status' => $newStatus,
            ];

            $this->applyTimestamps(
                updates: $updates,
                task: $lockedTask,
                newStatus: $newStatus,
            );

            /*
             * Persist the status.
             */
            $lockedTask->update($updates);

            /*
             * Audit log.
             */
            $lockedTask->logs()->create([
                'actor_id' => $actor->id,
                'event_type' => TaskLogEventType::STATUS_CHANGED,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'message' => $message,
                'metadata' => $metadata,
            ]);

            /*
             * No Group relationship.
             */
            return [
                'task' => $lockedTask->fresh([
                    'assignee',
                    'assignor',
                    'author',
                ]),
                'old_status' => $oldStatus,
            ];
        });

        /*
         * Dispatch only after successful commit.
         */
        TaskStatusChanged::dispatch(
            task: $result['task'],
            fromStatus: $result['old_status'],
            toStatus: $newStatus,
            actor: $actor,
            message: $message,
            metadata: $metadata,
        );

        return $result['task'];
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    private function authorize(
        Task $task,
        TaskStatus $newStatus,
        Staff $actor,
    ): void {
        $isAssignee = $task->assignee_id === $actor->id;
        $isAssignor = $task->assignor_id === $actor->id;

        /*
         * =========================================================
         * ASSIGNEE ACTIONS
         * =========================================================
         *
         * ACCEPTED -> IN_PROGRESS
         * IN_PROGRESS -> AWAITING_ACCEPTANCE
         */
        $isAssigneeAction = match (true) {
            $task->status === TaskStatus::ACCEPTED
                && $newStatus === TaskStatus::IN_PROGRESS => true,

            $task->status === TaskStatus::IN_PROGRESS
                && $newStatus === TaskStatus::AWAITING_ACCEPTANCE => true,

            default => false,
        };

        if ($isAssigneeAction && ! $isAssignee) {
            throw new AuthorizationException(
                'Faqat vazifa biriktirilgan xodim ushbu amalni bajara oladi.'
            );
        }

        /*
         * =========================================================
         * ASSIGNOR ACTIONS
         * =========================================================
         *
         * AWAITING_ACCEPTANCE -> ACCEPTED
         * AWAITING_ACCEPTANCE -> IN_PROGRESS
         * ACCEPTED -> CLOSED
         */
        $isAssignorAction = match (true) {
            $task->status === TaskStatus::AWAITING_ACCEPTANCE
                && in_array(
                    $newStatus,
                    [
                        TaskStatus::ACCEPTED,
                        TaskStatus::IN_PROGRESS,
                    ],
                    true
                ) => true,

            $task->status === TaskStatus::ACCEPTED
                && $newStatus === TaskStatus::CLOSED => true,

            default => false,
        };

        // if ($isAssignorAction && ! $isAssignor) {
        //     throw new AuthorizationException(
        //         'Faqat vazifani biriktirgan xodim ushbu amalni amalga oshira oladi.'
        //     );
        // }

        /*
         * =========================================================
         * CANCELLATION
         * =========================================================
         */
        if (
            $newStatus === TaskStatus::CANCELLED
            && ! $isAssignor
        ) {
            throw new AuthorizationException(
                'Sizda ushbu vazifani bekor qilish huquqi yo‘q.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Transition validation
    |--------------------------------------------------------------------------
    */

    private function validateTransition(
        TaskStatus $current,
        TaskStatus $target,
    ): void {
        $allowed = match ($current) {
            TaskStatus::CREATED => [
                TaskStatus::ASSIGNED,
                TaskStatus::AWAITING_ACCEPTANCE,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::ASSIGNED => [
                TaskStatus::ACCEPTED,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::ACCEPTED => [
                TaskStatus::IN_PROGRESS,
                TaskStatus::CLOSED,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::IN_PROGRESS => [
                TaskStatus::AWAITING_ACCEPTANCE,
                TaskStatus::CANCELLED,
            ],

            TaskStatus::AWAITING_ACCEPTANCE => [
                TaskStatus::COMPLETION_APPROVED,
                TaskStatus::IN_PROGRESS,
                TaskStatus::RETURNED,
            ],

            TaskStatus::COMPLETION_APPROVED => [
                TaskStatus::CLOSED,
            ],

            TaskStatus::CLOSED => [],

            TaskStatus::CANCELLED => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException(
                sprintf(
                    'Cannot change task status from "%s" to "%s".',
                    $current->value,
                    $target->value
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Timestamp handling
    |--------------------------------------------------------------------------
    */

    private function applyTimestamps(
        array &$updates,
        Task $task,
        TaskStatus $newStatus,
    ): void {
        if ($newStatus === TaskStatus::IN_PROGRESS) {
            if (! $task->started_at) {
                $updates['started_at'] = now();
            }

            /*
             * A returned task is no longer considered completed.
             */
            $updates['completed_at'] = null;
        }

        if ($newStatus === TaskStatus::AWAITING_ACCEPTANCE) {
            $updates['completed_at'] = now();
        }

        if ($newStatus === TaskStatus::CLOSED) {
            $updates['closed_at'] = now();

            /*
             * Safety fallback.
             */
            if (! $task->completed_at) {
                $updates['completed_at'] = now();
            }
        }
    }
}
