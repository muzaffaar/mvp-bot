<?php

namespace App\Services\Task;

use App\Enums\TaskLogEventType;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskTelegramMessage;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskStatusTransitionService
{
    /**
     * Accept an open Telegram task.
     *
     * Open tasks are not associated with an App\Models\Group.
     *
     * Eligibility is determined by:
     *
     *     A TaskTelegramMessage row exists for (task, actor) — i.e. the
     *     actor was one of the staff members the original group broadcast
     *     was sent to (resolved once, at creation time, from the real
     *     Telegram group chat — see TaskCreationService::resolveRecipients()).
     *
     * $chatId is NOT the group's chat ID here: the "Accept" button lives in
     * each candidate's own private chat with the bot (broadcast messages
     * are sent as individual DMs, not posted in the group), so it cannot
     * be compared against Staff.group_chat_id. It is kept only for logging.
     *
     * The actual assignment is protected by a database row lock so
     * only one eligible staff member can win the race.
     */
    public function acceptGroupTask(
        Task $task,
        Staff $actor,
        int $chatId,
    ): Task {
        Log::info('acceptGroupTask: attempt started.', [
            'task_id' => $task->id,
            'staff_id' => $actor->id,
            'staff_status' => $actor->status,
            'chat_id' => $chatId,
            'assignment_type' => $task->assignment_type?->value,
            'task_status' => $task->status?->value,
            'assignee_id' => $task->assignee_id,
        ]);

        /*
         * Cheap validation before opening the transaction.
         *
         * These checks are NOT the concurrency protection.
         */
        if (
            $task->assignment_type?->value !== 'group'
            || $task->assignee_id !== null
        ) {
            Log::info('acceptGroupTask: rejected, not an open group task.', [
                'task_id' => $task->id,
                'staff_id' => $actor->id,
            ]);

            throw new DomainException(
                'Bu vazifa ochiq vazifa emas.'
            );
        }

        /*
         * The task must be waiting for somebody to accept it.
         */
        if ($task->status !== TaskStatus::ASSIGNED) {
            Log::info('acceptGroupTask: rejected, task not in ASSIGNED status.', [
                'task_id' => $task->id,
                'staff_id' => $actor->id,
                'task_status' => $task->status?->value,
            ]);

            throw new DomainException(
                'Bu vazifa allaqachon qabul qilingan yoki mavjud emas.'
            );
        }

        /*
         * Verify eligibility before entering the critical section.
         *
         * This is only a cheap preliminary check.
         *
         * The same check is repeated after acquiring the task lock.
         */
        $isGroupMember = $this->isEligibleTelegramMember(
            task: $task,
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
             * Verify that the actor is still eligible to claim this
             * task now that we hold the row lock.
             *
             * There is intentionally NO Task->group relationship.
             */
            if (! $this->isEligibleTelegramMember(
                task: $lockedTask,
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

            Log::info('acceptGroupTask: accepted successfully.', [
                'task_id' => $lockedTask->id,
                'staff_id' => $actor->id,
                'chat_id' => $chatId,
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
     * Check whether a staff member is allowed to claim this open group
     * task.
     *
     * Two conditions:
     *
     *   1. The staff member is currently active.
     *   2. They were one of the candidates the original group broadcast
     *      was sent to — i.e. a TaskTelegramMessage row exists linking
     *      them to this task (created once, at creation time, from the
     *      real Telegram group's membership — see
     *      TaskCreationService::resolveRecipients()).
     *
     * $chatId cannot be used for this check: the "Accept" button lives in
     * the candidate's own private chat with the bot, not the group chat,
     * so Staff.group_chat_id (the group's chat ID) is never comparable to
     * it. $chatId is accepted only so it can be logged for diagnostics.
     */
    private function isEligibleTelegramMember(
        Task $task,
        Staff $actor,
        int $chatId,
    ): bool {
        if ($actor->status !== 'active') {
            Log::info('isEligibleTelegramMember: rejected, staff not active.', [
                'task_id' => $task->id,
                'staff_id' => $actor->id,
                'staff_status' => $actor->status,
                'chat_id' => $chatId,
            ]);

            return false;
        }

        $wasOriginalRecipient = TaskTelegramMessage::query()
            ->where('task_id', $task->id)
            ->where('staff_id', $actor->id)
            ->whereIn('role', ['notification', 'update'])
            ->exists();

        Log::info('isEligibleTelegramMember: eligibility check.', [
            'task_id' => $task->id,
            'staff_id' => $actor->id,
            'chat_id' => $chatId,
            'was_original_recipient' => $wasOriginalRecipient,
        ]);

        return $wasOriginalRecipient;
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

    /**
     * Gated by the TARGET status being entered, mirroring exactly how
     * TaskStatusCallback (the Telegram bot) authorizes each button press —
     * so a task's status can never be changed by "everyone" through this
     * shared entry point, whether the caller is the bot or the admin
     * panel's kanban board.
     */
    private function authorize(
        Task $task,
        TaskStatus $newStatus,
        Staff $actor,
    ): void {
        $isAssignee = $task->assignee_id === $actor->id;
        $isAssignor = $task->assignor_id === $actor->id;

        /*
         * Qabul qilish: ASSIGNED -> ACCEPTED. Only the assignee.
         */
        if ($newStatus === TaskStatus::ACCEPTED && ! $isAssignee) {
            throw new AuthorizationException(
                'Faqat vazifa biriktirilgan xodim ushbu amalni bajara oladi.'
            );
        }

        /*
         * Ishni boshlash: ACCEPTED -> IN_PROGRESS. Only the assignee.
         */
        if (
            $newStatus === TaskStatus::IN_PROGRESS
            && $task->status === TaskStatus::ACCEPTED
            && ! $isAssignee
        ) {
            throw new AuthorizationException(
                'Faqat vazifa biriktirilgan xodim ushbu amalni bajara oladi.'
            );
        }

        /*
         * Bajarildi: IN_PROGRESS -> AWAITING_ACCEPTANCE. Only the assignee
         * (the comment-required rule lives at the request-validation layer,
         * not here — this only gates WHO may make the move).
         */
        if ($newStatus === TaskStatus::AWAITING_ACCEPTANCE && ! $isAssignee) {
            throw new AuthorizationException(
                'Faqat vazifa biriktirilgan xodim ushbu amalni bajara oladi.'
            );
        }

        /*
         * Qaytarish: AWAITING_ACCEPTANCE -> IN_PROGRESS. This is the
         * assignor sending the completed work back for rework — distinct
         * from "Ishni boshlash" above, which starts from ACCEPTED. Only
         * the assignor.
         */
        if (
            $newStatus === TaskStatus::IN_PROGRESS
            && $task->status === TaskStatus::AWAITING_ACCEPTANCE
            && ! $isAssignor
        ) {
            throw new AuthorizationException(
                'Faqat vazifani biriktirgan xodim ushbu amalni amalga oshira oladi.'
            );
        }

        /*
         * Tasdiqlash: -> COMPLETION_APPROVED. Only the assignor.
         */
        if ($newStatus === TaskStatus::COMPLETION_APPROVED && ! $isAssignor) {
            throw new AuthorizationException(
                'Faqat vazifani biriktirgan xodim ushbu amalni amalga oshira oladi.'
            );
        }

        /*
         * Yopish: -> CLOSED (whether from ACCEPTED or COMPLETION_APPROVED).
         * Only the assignor.
         */
        if ($newStatus === TaskStatus::CLOSED && ! $isAssignor) {
            throw new AuthorizationException(
                'Faqat vazifani biriktirgan xodim ushbu amalni amalga oshira oladi.'
            );
        }

        /*
         * Bekor qilish: -> CANCELLED. Only the assignor.
         */
        if ($newStatus === TaskStatus::CANCELLED && ! $isAssignor) {
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

    /**
     * The single source of truth for the task lifecycle graph. The panel
     * and the Telegram bot both change status through
     * TaskService::changeStatus() / here, so they can never drift into two
     * different sets of "valid" transitions by hand — and it's public so
     * the kanban board can expose the graph to the frontend for live
     * drag-and-drop feedback without duplicating it a third time in JS.
     */
    public function allowedTargets(TaskStatus $current): array
    {
        return match ($current) {
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

            TaskStatus::CLOSED,
            TaskStatus::CANCELLED,
            TaskStatus::RETURNED => [],
        };
    }

    public function validateTransition(
        TaskStatus $current,
        TaskStatus $target,
    ): void {
        if (! in_array($target, $this->allowedTargets($current), true)) {
            throw new DomainException(
                sprintf(
                    'Cannot change task status from "%s" to "%s".',
                    $current->value,
                    $target->value
                )
            );
        }
    }

    /**
     * Boolean form of authorize() + validateTransition(), for callers that
     * need to know in advance whether an actor may make a specific move
     * (e.g. the kanban board deciding which columns to light up while a
     * card is being dragged) rather than attempting the change and
     * catching an exception.
     */
    public function canTransition(
        Task $task,
        TaskStatus $target,
        Staff $actor,
    ): bool {
        try {
            $this->authorize($task, $target, $actor);
            $this->validateTransition($task->status, $target);

            return true;
        } catch (AuthorizationException|DomainException) {
            return false;
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
