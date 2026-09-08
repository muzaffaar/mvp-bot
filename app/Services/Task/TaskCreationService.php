<?php

namespace App\Services\Task;

use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskTelegramMessage;
use App\Telegram\Services\TelegramClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Support\TashkentDateTime;

class TaskCreationService
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly TelegramClient $telegram,
    ) {}

    public function create(
        array $context,
        Staff $creator,
        int $chatId,
    ): Task {
        $assigneeId = $context['assignee_id'] ?? null;

        $assignmentType = $assigneeId
            ? 'direct'
            : 'group';

        $task = $this->taskService->create(
            data: [
                'title' => $context['title'],
                'description' => $context['description'] ?? null,
                'assignee_id' => $assigneeId,
                'assignment_type' => $assignmentType,
                'priority' => $context['priority'] ?? 'normal',
                'deadline' => TashkentDateTime::normalize($context['deadline'] ?? null),

                'source_type' =>
                    $context['source_type'] ?? 'text',

                'source_id' =>
                    $context['source_id'] ?? null,

                'source_message_id' =>
                    $context['source_message_id']
                    ?? $context['message_id']
                    ?? null,

                'source_url' =>
                    $context['source_url'] ?? null,
            ],
            actor: $creator,
        );

        $task->load([
            'assignee',
            'assignor',
        ]);

        $this->notifyRecipients(
            task: $task,
            creator: $creator,
            chatId: $chatId,
        );

        return $task;
    }

    private function notifyRecipients(
        Task $task,
        Staff $creator,
        int $chatId,
    ): void {
        $recipients = $this->resolveRecipients(
            task: $task,
            creator: $creator,
            chatId: $chatId,
        );

        Log::info('Resolved task notification recipients', [
            'task_id' => $task->id,
            'task_number' => $task->task_number,
            'assignment_type' => $task->assignment_type,
            'telegram_group_chat_id' => $chatId,
            'recipient_count' => $recipients->count(),
            'recipient_ids' => $recipients
                ->pluck('id')
                ->values()
                ->all(),
        ]);

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
                $response = $this->telegram->sendMessage(
                    $recipient->telegram_chat_id,
                    $this->buildRecipientMessage(
                        task: $task,
                        creator: $creator,
                    ),
                    parseMode: 'HTML',
                    replyMarkup: $keyboard,
                );

                TaskTelegramMessage::create([
                    'task_id' => $task->id,
                    'staff_id' => $recipient->id,
                    'chat_id' => $recipient->telegram_chat_id,
                    'message_id' => $response['result']['message_id'],
                ]);

                Log::info('Task notification sent', [
                    'task_id' => $task->id,
                    'task_number' => $task->task_number,
                    'recipient_id' => $recipient->id,
                    'recipient_name' => $recipient->full_name,
                    'telegram_user_id' =>
                        $recipient->telegram_chat_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send task notification', [
                    'task_id' => $task->id,
                    'recipient_id' => $recipient->id,
                    'telegram_user_id' =>
                        $recipient->telegram_chat_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

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

    private function formatDeadline(mixed $deadline): string
{
    if (! $deadline) {
        return 'Belgilanmagan';
    }

    try {
        return TashkentDateTime::format($deadline);
    } catch (\Throwable) {
        return 'Belgilanmagan';
    }
}

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
}
