<?php

namespace App\Telegram\Callbacks;

use App\AI\Services\ConversationService;
use App\Enums\Permission;
use App\Enums\TaskAssignmentType;
use App\Enums\TelegramConversationState;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskTelegramMessage;
use App\Services\Task\TaskStatusTransitionService;
use App\Telegram\Services\TelegramClient;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskStatusCallback
{
    public function __construct(
        private readonly TaskStatusTransitionService $statusTransition,
        private readonly ConversationService $conversationService,
        private readonly TelegramClient $telegram,
    ) {}

    public function handle(
        Staff $staff,
        int $taskId,
        TaskStatus $newStatus,
        string $callbackQueryId,
        int $chatId,
        int $messageId,
        bool $preserveMessage = false,
    ): void {
        $task = Task::query()
            ->with([
                'assignee'
            ])
            ->find($taskId);

        if (! $task) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Vazifa topilmadi.',
            );

            return;
        }

        /*
         * ============================================================
         * AUTHORIZATION
         * ============================================================
         */

        /*
         * Qabul qilish / tasdiqlash:
         *
         * This action is performed by the assignor when the task
         * reaches AWAITING_ACCEPTANCE.
         */
        if (
            $newStatus === TaskStatus::COMPLETION_APPROVED
            && $task->assignor_id !== $staff->id
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Faqat vazifa beruvchisi tasdiqlashi mumkin.',
            );

            return;
        }

        /*
         * Yopish:
         *
         * Only the assignor can close the task.
         */
        if (
            $newStatus === TaskStatus::CLOSED
            && $task->assignor_id !== $staff->id
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Faqat vazifa beruvchisi yopishi mumkin.',
            );

            return;
        }

        /*
         * Qaytarish:
         *
         * AWAITING_ACCEPTANCE -> IN_PROGRESS
         *
         * This IN_PROGRESS transition is NOT "Ishni boshlash".
         * It is the assignor returning the completed task back
         * to the assignee.
         */
        if (
            $newStatus === TaskStatus::IN_PROGRESS
            && $task->status === TaskStatus::AWAITING_ACCEPTANCE
            && $task->assignor_id !== $staff->id
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Faqat vazifa beruvchisi vazifani qaytarishi mumkin.',
            );

            return;
        }

        /*
         * Qabul qilish (assignment):
         *
         * ASSIGNED -> ACCEPTED on a direct task (or an already-claimed
         * group task) may only be performed by the actual assignee.
         * An unclaimed GROUP task is a separate case, authorized by
         * acceptGroupTask()'s own Telegram-chat-membership check.
         */
        $isUnclaimedGroupTask =
            $task->assignment_type === TaskAssignmentType::GROUP
            && $task->status === TaskStatus::ASSIGNED
            && $task->assignee_id === null;

        if (
            $newStatus === TaskStatus::ACCEPTED
            && ! $isUnclaimedGroupTask
            && $task->assignee_id !== $staff->id
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Bu vazifa sizga biriktirilmagan.',
            );

            return;
        }

        /*
         * Permission depends on the actual transition, not only
         * the target status.
         */
        $permission = $this->permissionForTransition(
            task: $task,
            newStatus: $newStatus,
        );

        if (! $staff->can($permission->value)) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Sizda bu amalni bajarish huquqi mavjud emas.',
            );

            return;
        }

        /*
         * ============================================================
         * IN_PROGRESS -> AWAITING_ACCEPTANCE
         * ============================================================
         *
         * Completion requires a comment first.
         */
        if ($newStatus === TaskStatus::AWAITING_ACCEPTANCE) {
            if ($task->assignee_id !== $staff->id) {
                $this->telegram->answerCallbackQuery(
                    $callbackQueryId,
                    '❌ Bu vazifa sizga biriktirilmagan.',
                );

                return;
            }

            $this->requestCompletionComment(
                staff: $staff,
                task: $task,
                callbackQueryId: $callbackQueryId,
                chatId: $chatId,
                messageId: $messageId,
                preserveMessage: $preserveMessage,
            );

            return;
        }

        try {
            /*
             * ========================================================
             * GROUP TASK CLAIM
             * ========================================================
             *
             * ASSIGNED + GROUP + no assignee
             *                  ↓
             *               ACCEPTED
             *                  ↓
             *        accepting staff becomes assignee
             */
            Log::info('Task status callback debug', [
                'task_id' => $task->id,
                'actor_id' => $staff->id,
                'new_status' => $newStatus->value,
                'assignment_type' => $task->assignment_type?->value,
                'current_status' => $task->status?->value,
                'assignee_id' => $task->assignee_id,
            ]);

            if (
                $newStatus === TaskStatus::ACCEPTED
                && $task->assignment_type === TaskAssignmentType::GROUP
                && $task->status === TaskStatus::ASSIGNED
                && $task->assignee_id === null
            ) {
                $task = $this->statusTransition->acceptGroupTask(
                    task: $task,
                    actor: $staff,
                    chatId: $chatId
                );
                $this->deleteGroupTaskMessages($task);
            } else {
                /*
                 * All other transitions use the status requested
                 * by the Telegram callback button.
                 */
                $task = $this->statusTransition->change(
                    task: $task,
                    newStatus: $newStatus,
                    actor: $staff,
                );
            }

            /*
             * Qaytarish: remove every completion-media message that was
             * previously sent to the assignor for this submission.
             *
             * The notification sends media as separate Telegram messages,
             * so the callback message deletion above is not sufficient.
             */
            if (
                $newStatus === TaskStatus::IN_PROGRESS
                && $task->status === TaskStatus::IN_PROGRESS
                && $task->metadata !== null
            ) {
                $this->deleteCompletionNotificationMedia($task);
            }

            if (! $preserveMessage && (
                $newStatus === TaskStatus::ACCEPTED ||
                $newStatus === TaskStatus::IN_PROGRESS ||
                $newStatus === TaskStatus::AWAITING_ACCEPTANCE ||
                $newStatus === TaskStatus::COMPLETION_APPROVED
                || $newStatus === TaskStatus::CLOSED
            )) {
                try {
                    $this->telegram->deleteMessage(
                        chatId: $chatId,
                        messageId: $messageId,
                    );
                } catch (Throwable $e) {
                    Log::warning(
                        'Failed to delete Telegram task status message.',
                        [
                            'task_id' => $task->id,
                            'status' => $newStatus->value,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ],
                    );
                }
            }

            if ($preserveMessage && $newStatus !== TaskStatus::AWAITING_ACCEPTANCE) {
                $freshTask = Task::query()->find($task->id);
                if ($freshTask) {
                    $this->telegram->editMessageReplyMarkup(
                        chatId: $chatId,
                        messageId: $messageId,
                        replyMarkup: $this->keyboardFor($freshTask),
                    );
                }
            }

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                match ($newStatus) {
                    TaskStatus::ACCEPTED =>
                        'Vazifa qabul qilindi.',

                    TaskStatus::IN_PROGRESS =>
                        'Ish boshlandi.',

                    TaskStatus::AWAITING_ACCEPTANCE =>
                        'Vazifa yakunlandi.',

                    TaskStatus::COMPLETION_APPROVED =>
                        'Vazifa tasdiqlandi.',

                    TaskStatus::CLOSED =>
                        'Vazifa yopildi.',

                    default =>
                        'Vazifa statusi yangilandi.',
                },
            );
        } catch (AuthorizationException $e) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                $e->getMessage(),
            );
        } catch (DomainException $e) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                $e->getMessage(),
            );
        } catch (Throwable $e) {
            report($e);

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Vazifa statusini o‘zgartirishda xatolik.',
            );
        }
    }

    /**
     * Resolve permission based on the actual transition.
     *
     * IN_PROGRESS has two different meanings:
     *
     * ACCEPTED -> IN_PROGRESS
     *     = Ishni boshlash
     *
     * AWAITING_ACCEPTANCE -> IN_PROGRESS
     *     = Qaytarish
     */
    private function permissionForTransition(
        Task $task,
        TaskStatus $newStatus,
    ): Permission {
        return match (true) {
            /*
             * Group/direct task acceptance.
             */
            $newStatus === TaskStatus::ACCEPTED
                => Permission::TaskAccept,

            /*
             * ACCEPTED -> IN_PROGRESS
             *
             * Assignee starts working.
             */
            $newStatus === TaskStatus::IN_PROGRESS
                && $task->status === TaskStatus::ACCEPTED
                => Permission::TaskStart,

            /*
             * IN_PROGRESS -> AWAITING_ACCEPTANCE
             *
             * Assignee submits completed work.
             */
            $newStatus === TaskStatus::AWAITING_ACCEPTANCE
                => Permission::TaskSubmit,

            /*
             * AWAITING_ACCEPTANCE -> COMPLETION_APPROVED
             *
             * Assignor approves the completed task.
             */
            $newStatus === TaskStatus::COMPLETION_APPROVED
                => Permission::TaskApproved,

            /*
             * COMPLETION_APPROVED -> CLOSED
             *
             * Assignor closes the task.
             */
            $newStatus === TaskStatus::CLOSED
                => Permission::TaskArchive,

            /*
             * AWAITING_ACCEPTANCE -> IN_PROGRESS
             *
             * Assignor returns the task to the assignee.
             */
            $newStatus === TaskStatus::IN_PROGRESS
                && $task->status === TaskStatus::AWAITING_ACCEPTANCE
                => Permission::TaskUpdate,

            default
                => Permission::TaskUpdate,
        };
    }

    private function requestCompletionComment(
        Staff $staff,
        Task $task,
        string $callbackQueryId,
        int $chatId,
        int $messageId,
        bool $preserveMessage = false,
    ): void {
        /*
         * The task must currently be IN_PROGRESS.
         */
        if ($task->status !== TaskStatus::IN_PROGRESS) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Vazifa hozir bajarilayotgan holatda emas.',
            );

            return;
        }

        /*
         * Only the assignee can complete the task.
         */
        if ($task->assignee_id !== $staff->id) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Bu vazifa sizga biriktirilmagan.',
            );

            return;
        }

        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $this->conversationService->setState(
            $conversation,
            TelegramConversationState::WAITING_TASK_COMPLETION_COMMENT,
        );

        $context = [
            'task_id' => $task->id,
        ];

        if ($preserveMessage) {
            $context['management_card_message_id'] = $messageId;
            $context['management_card_chat_id'] = $chatId;
        }

        $this->conversationService->setContext(
            $conversation,
            $context,
        );

        $this->telegram->answerCallbackQuery(
            $callbackQueryId,
            'Izoh kiritishingiz kerak.',
        );

        if (! $preserveMessage) {
            $this->telegram->deleteMessage($chatId, $messageId);
        }

        $this->telegram->sendMessage(
            $chatId,
            "📝 <b>Vazifani yakunlash</b>\n\n"
            . "🔢 <b>Raqam:</b> {$task->task_number}\n"
            . "📌 <b>Vazifa:</b> {$task->title}\n\n"
            . "Vazifa bo‘yicha bajargan ishlaringiz haqida "
            . "<b>izoh yozing</b>.\n\n"
            . "Masalan:\n"
            . "• Server tekshirildi\n"
            . "• Muammo aniqlandi va tuzatildi\n"
            . "• Hisobot tayyorlandi va yuborildi",
            parseMode: 'HTML',
        );
    }

    private function sendStatusMessage(
        Task $task,
        int $chatId,
    ): void {
        $message =
            "📋 <b>Vazifa statusi yangilandi</b>\n\n"
            . "🔢 <b>Raqam:</b> {$task->task_number}\n"
            . "📌 <b>Vazifa:</b> {$task->title}\n"
            . "📊 <b>Status:</b> {$task->status->label()}";

        $keyboard = $this->keyboardFor($task);

        $this->telegram->sendMessage(
            $chatId,
            $message,
            parseMode: 'HTML',
            replyMarkup: $keyboard,
        );
    }

    private function keyboardFor(Task $task): ?array
    {
        return match ($task->status) {
            TaskStatus::ASSIGNED => [
                'inline_keyboard' => [[
                    [
                        'text' => '✅ Vazifani qabul qilaman',
                        'callback_data' =>
                            "task:status:{$task->id}:accepted",
                    ],
                ]],
            ],

            TaskStatus::ACCEPTED => [
                'inline_keyboard' => [[
                    [
                        'text' => '▶️ Ishni boshladim',
                        'callback_data' =>
                            "task:status:{$task->id}:in_progress",
                    ],
                ]],
            ],

            TaskStatus::IN_PROGRESS => [
                'inline_keyboard' => [[
                    [
                        'text' => '✅ Bajardim — tasdiqqa yuboraman',
                        'callback_data' =>
                            "task:status:{$task->id}:awaiting_acceptance",
                    ],
                ]],
            ],

            default => null,
        };
    }

    private function deleteCompletionNotificationMedia(Task $task): void
    {
        $messageIds = data_get(
            $task->metadata,
            'completion_notification_media_message_ids',
            [],
        );

        if (! is_array($messageIds) || $messageIds === []) {
            return;
        }

        $chatId = $task->assignor?->telegram_chat_id;

        if (! $chatId) {
            Log::warning('Cannot delete completion media: assignor chat ID missing.', [
                'task_id' => $task->id,
                'assignor_id' => $task->assignor_id,
            ]);
            return;
        }

        foreach ($messageIds as $messageId) {
            try {
                $this->telegram->deleteMessage(
                    chatId: $chatId,
                    messageId: (int) $messageId,
                );
            } catch (Throwable $e) {
                Log::warning('Failed to delete returned task completion media message.', [
                    'task_id' => $task->id,
                    'assignor_id' => $task->assignor_id,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $freshTask = Task::query()->find($task->id);
        if ($freshTask) {
            $metadata = $freshTask->metadata ?? [];
            unset($metadata['completion_notification_media_message_ids']);
            $freshTask->update(['metadata' => $metadata]);
        }
    }

    private function deleteGroupTaskMessages(Task $task): void
    {
        /*
         * Only two roles are ever eligible for cleanup here:
         *
         *   'notification' — the original per-candidate "tap to accept"
         *                     broadcast DM (has a now-stale Accept button).
         *   'update'        — a follow-up notice (edit/deadline/reminder)
         *                     sent to candidates while the task was open.
         *
         * Every other role — 'task_created_notification' (the group's
         * "✅ Vazifa yaratildi" announcement), 'original' (the user's own
         * message that triggered task creation), 'context', 'task_update',
         * etc. — is historical record, not a stale action prompt, and must
         * never be touched here.
         */
        $messages = TaskTelegramMessage::query()
            ->where('task_id', $task->id)
            ->whereIn('role', ['notification', 'update'])
            ->get();

        foreach ($messages as $message) {
            /*
             * Keep the accepting assignee's own copy of any "update"
             * message (edits/deadline changes/reminders sent while the
             * task was still open to the whole group). Every other
             * candidate's copy, and every original "tap to accept"
             * broadcast message (including the assignee's own), is
             * removed as before.
             */
            if (
                $message->role === 'update'
                && (int) $message->staff_id === (int) $task->assignee_id
            ) {
                continue;
            }

            try {
                $this->telegram->deleteMessage(
                    chatId: $message->chat_id,
                    messageId: $message->message_id,
                );
            } catch (Throwable $e) {
                Log::warning('Failed to delete group task Telegram message.', [
                    'task_id' => $task->id,
                    'staff_id' => $message->staff_id,
                    'chat_id' => $message->chat_id,
                    'message_id' => $message->message_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $message->delete();
        }
    }
}
