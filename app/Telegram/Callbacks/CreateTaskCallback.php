<?php

namespace App\Telegram\Callbacks;

use App\AI\Services\ConversationService;
use App\Enums\Permission;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Telegram\Services\TelegramClient;
use Carbon\Carbon;
use App\Support\TashkentDateTime;
use Illuminate\Support\Facades\Log;

class CreateTaskCallback
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly TaskService $taskService,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function confirm(
        Staff $staff,
        int $chatId,
        string $callbackQueryId,
        int $messageId,
    ): void {
        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $context = $this->conversationService->context($conversation);

        if (empty($context['title'])) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Vazifa maʼlumotlari topilmadi.'
            );

            $this->conversationService->reset($conversation);

            return;
        }

        /*
         * =====================================================
         * PERMISSION
         * =====================================================
         */

        if (! $staff->can(Permission::TaskCreate->value)) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Sizda vazifa yaratish huquqi mavjud emas.'
            );

            return;
        }

        try {
            /*
             * =====================================================
             * SOURCE INFORMATION
             * =====================================================
             *
             * source_type represents the ORIGINAL Telegram
             * message type that caused this task to be created.
             *
             * Examples:
             *   text
             *   voice
             *   file
             *   photo
             *   video
             *   audio
             */

            $sourceType = $context['source_type'] ?? 'text';

            $sourceId = $context['source_id'] ?? null;

            $sourceMessageId =
                $context['source_message_id']
                ?? $context['message_id']
                ?? null;

            $sourceUrl = $context['source_url'] ?? null;

            /*
             * =====================================================
             * ASSIGNMENT
             * =====================================================
             *
             * The AI does NOT determine group information.
             *
             * If assignee_id exists:
             *     direct task
             *
             * If assignee_id is null:
             *     open task for eligible members of the
             *     current Telegram chat.
             *
             * The current Telegram chat is represented by $chatId.
             */

            $assigneeId = $context['assignee_id'] ?? null;

            $assignmentType = $assigneeId
                ? 'direct'
                : 'group';

            /*
             * =====================================================
             * CREATE TASK
             * =====================================================
             */

            $task = $this->taskService->create(
                data: [
                    'title' =>
                        $context['title'],

                    'description' =>
                        $context['description'] ?? null,

                    'assignee_id' =>
                        $assigneeId,

                    'assignment_type' =>
                        $assignmentType,

                    'priority' =>
                        $context['priority'] ?? 'normal',

                    'deadline' =>
                        $context['deadline'] ?? null,

                    /*
                     * Original Telegram input.
                     */
                    'source_type' =>
                        $sourceType,

                    /*
                     * External source identifier.
                     */
                    'source_id' =>
                        $sourceId,

                    /*
                     * Telegram message ID.
                     */
                    'source_message_id' =>
                        $sourceMessageId,

                    /*
                     * Telegram/source URL if available.
                     */
                    'source_url' =>
                        $sourceUrl,
                ],
                actor: $staff,
            );

            /*
             * Reload only relationships that are still relevant.
             *
             * There is intentionally NO "group" relationship.
             */
            $task->load([
                'assignee',
                'assignor',
            ]);

            /*
             * =====================================================
             * RESET CONVERSATION
             * =====================================================
             */

            $this->conversationService->reset($conversation);

            /*
             * Remove confirmation message.
             */
            $this->telegram->deleteMessage(
                chatId: $chatId,
                messageId: $messageId,
            );

            /*
             * =====================================================
             * CALLBACK RESPONSE
             * =====================================================
             */

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Vazifa yaratildi.'
            );

            /*
             * =====================================================
             * CREATOR NOTIFICATION
             * =====================================================
             */

            $this->telegram->sendMessage(
                $chatId,
                $this->buildCreatorMessage($task),
                parseMode: 'HTML',
            );

            /*
             * =====================================================
             * RECIPIENTS
             * =====================================================
             */

            $recipients = $this->resolveRecipients(
                task: $task,
                creator: $staff,
                chatId: $chatId,
            );

            Log::info('Resolved task notification recipients', [
                'task_id' => $task->id,
                'task_number' => $task->task_number,
                'assignment_type' => $assignmentType,
                'telegram_group_chat_id' => $chatId,
                'recipient_count' => $recipients->count(),
                'recipient_ids' => $recipients
                    ->pluck('id')
                    ->values()
                    ->all(),
            ]);

            /*
             * =====================================================
             * NOTIFY RECIPIENTS
             * =====================================================
             */

            $keyboard = [
                'inline_keyboard' => [[
                    [
                        'text' => '✅ Qabul qilish',
                        'callback_data' =>
                            "task:status:{$task->id}:accepted",
                    ],
                ]],
            ];

            foreach ($recipients as $recipient) {
                try {
                    $this->telegram->sendMessage(
                        $recipient->telegram_chat_id,
                        $this->buildRecipientMessage(
                            task: $task,
                            creator: $staff,
                        ),
                        parseMode: 'HTML',
                        replyMarkup: $keyboard,
                    );

                    Log::info(
                        'Task notification sent',
                        [
                            'task_id' =>
                                $task->id,

                            'task_number' =>
                                $task->task_number,

                            'recipient_id' =>
                                $recipient->id,

                            'recipient_name' =>
                                $recipient->full_name,

                            'telegram_user_id' =>
                                $recipient->telegram_chat_id,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error(
                        'Failed to send task notification',
                        [
                            'task_id' =>
                                $task->id,

                            'recipient_id' =>
                                $recipient->id,

                            'telegram_user_id' =>
                                $recipient->telegram_chat_id,

                            'error' =>
                                $e->getMessage(),
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            report($e);

            Log::error(
                'Failed to create task from Telegram callback',
                [
                    'staff_id' => $staff->id,
                    'telegram_chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]
            );

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Vazifani yaratishda xatolik.'
            );
        }
    }

    /**
     * Resolve Telegram recipients for the newly created task.
     *
     * Direct task:
     *     Only the explicitly assigned staff member.
     *
     * Open task:
     *     Active staff belonging to the current Telegram chat.
     *
     * IMPORTANT:
     *     telegram_chat_id on Staff represents the Telegram USER ID.
     *     group_chat_id represents the Telegram GROUP/CHAT ID.
     */
    private function resolveRecipients(
        Task $task,
        Staff $creator,
        int $chatId,
    ) {
        /*
         * =====================================================
         * DIRECT TASK
         * =====================================================
         */

        if ($task->assignee) {
            Log::info(
                'Sending direct task notification',
                [
                    'task_id' =>
                        $task->id,

                    'task_number' =>
                        $task->task_number,

                    'assignee_id' =>
                        $task->assignee->id,

                    'assignee_name' =>
                        $task->assignee->full_name,

                    'telegram_user_id' =>
                        $task->assignee->telegram_chat_id,
                ]
            );

            if (
                ! $task->assignee->telegram_chat_id
                || $task->assignee->id === $creator->id
            ) {
                return collect();
            }

            return collect([$task->assignee]);
        }

        /*
         * =====================================================
         * OPEN TASK
         * =====================================================
         *
         * No Group model is involved.
         *
         * The current Telegram chat ID is the context.
         */

        $recipients = Staff::query()
            ->where('status', 'active')
            ->where('group_chat_id', $chatId)
            ->whereNotNull('telegram_chat_id')
            ->where('id', '!=', $creator->id)
            ->get();

        Log::info(
            'Sending open task notifications',
            [
                'task_id' =>
                    $task->id,

                'task_number' =>
                    $task->task_number,

                'telegram_group_chat_id' =>
                    $chatId,

                'recipient_count' =>
                    $recipients->count(),

                'recipient_ids' =>
                    $recipients
                        ->pluck('id')
                        ->values()
                        ->all(),
            ]
        );

        return $recipients;
    }

    /**
     * Build notification sent back to the task creator.
     */
    private function buildCreatorMessage(
        Task $task,
    ): string {
        $assignee = $task->assignee?->full_name
            ?? 'Barcha mavjud xodimlarga ochiq';

        return
            "✅ <b>Vazifa yaratildi</b>\n\n"
            . "🔢 <b>Raqam:</b> "
            . e($task->task_number)
            . "\n"
            . "📌 <b>Vazifa:</b> "
            . e($task->title)
            . "\n"
            . "👤 <b>Masʼul:</b> "
            . e($assignee);
    }

    /**
     * Build notification sent to task recipients.
     */
    private function buildRecipientMessage(
        Task $task,
        Staff $creator,
    ): string {
        $deadline = $this->formatDeadline(
            $task->deadline
        );

        $priority = $this->priorityLabel(
            $task->priority
        );

        $assignee = $task->assignee?->full_name;

        $message =
            "📋 <b>Yangi vazifa</b>\n\n"
            . "🔢 <b>Raqam:</b> "
            . e($task->task_number)
            . "\n"
            . "📌 <b>Vazifa:</b> "
            . e($task->title)
            . "\n"
            . "📝 <b>Tavsif:</b> "
            . e($task->description ?: '—')
            . "\n"
            . "👤 <b>Beruvchi:</b> "
            . e($creator->full_name ?: '—')
            . "\n"
            . "🔥 <b>Muhimlik:</b> "
            . $priority
            . "\n"
            . "⏰ <b>Muddat:</b> "
            . $deadline;

        /*
         * Direct task.
         *
         * For an open task, there is no specific assignee yet,
         * so do not display a fake "Masʼul" field.
         */
        if ($assignee) {
            $message .=
                "\n"
                . "👤 <b>Masʼul:</b> "
                . e($assignee);
        }

        return $message;
    }

    /**
     * Human-readable priority.
     */
    private function priorityLabel(
        mixed $priority,
    ): string {
        $value = $priority instanceof \BackedEnum
            ? $priority->value
            : (string) $priority;

        return match ($value) {
            'urgent' => '🔴 Juda yuqori',
            'high' => '🟠 Yuqori',
            'normal' => '🟡 Normal',
            'low' => '🟢 Past',
            default => e($value ?: 'normal'),
        };
    }

    /**
     * Human-readable deadline.
     */
    private function formatDeadline(
        mixed $deadline,
    ): string {
        if (! $deadline) {
            return 'Belgilanmagan';
        }

        try {
            return TashkentDateTime::format($deadline);
        } catch (\Throwable) {
            return 'Belgilanmagan';
        }
    }

    public function cancel(
        Staff $staff,
        int $chatId,
        string $callbackQueryId,
        int $messageId,
    ): void {
        if (! $staff->can(Permission::TaskCancel->value)) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Sizda vazifani bekor qilish huquqi mavjud emas.'
            );

            return;
        }

        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $this->conversationService->reset($conversation);

        $this->telegram->answerCallbackQuery(
            $callbackQueryId,
            'Bekor qilindi.'
        );

        $this->telegram->deleteMessage( 
            chatId: $chatId,
            messageId: $messageId,
        );

        $this->telegram->sendMessage(
            $chatId,
            '❌ Vazifa yaratish bekor qilindi.',
            parseMode: 'HTML',
        );
    }
}
