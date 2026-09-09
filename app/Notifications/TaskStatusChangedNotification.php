<?php

namespace App\Notifications;

use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Task $task,
        public readonly TaskStatus $fromStatus,
        public readonly TaskStatus $toStatus,
        public readonly Staff $actor,
        public readonly ?string $message = null,
        public readonly ?array $metadata = null,
    ) {
    }


    // public function toTelegramChatId(Staff $notifiable): ?int
    // {
    //     if ($this->task->assignor_id === $notifiable->id) {
    //         return (int) config('services.group.chat_id');
    //     }

    //     return null;
    // }

    public function via(Staff $notifiable): array
    {
        return [
            'database',
            TelegramChannel::class,
        ];
    }

    public function toDatabase(Staff $notifiable): array
    {
        return [
            'type' => 'task_status_changed',

            'task_id' => $this->task->id,
            'task_number' => $this->task->task_number,

            'title' => $this->task->title,

            'from_status' => $this->fromStatus->value,
            'from_status_label' => $this->fromStatus->label(),

            'to_status' => $this->toStatus->value,
            'to_status_label' => $this->toStatus->label(),

            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->full_name,

            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }

    public function toTelegram(Staff $notifiable): string
    {
        /*
         * ---------------------------------------------------------
         * ASSIGNED -> ACCEPTED
         * ---------------------------------------------------------
         *
         * A staff member accepted a newly assigned task.
         */
        if (
            $this->fromStatus === TaskStatus::ASSIGNED
            && $this->toStatus === TaskStatus::ACCEPTED
        ) {
            if ($this->task->assignee_id === $notifiable->id) {
                return
                    "📋 <b>Vazifa qabul qilindi</b>\n\n"
                    . "🔢 <b>Raqam:</b> "
                    . e($this->task->task_number)
                    . "\n"
                    . "📌 <b>Vazifa:</b> "
                    . e($this->task->title)
                    . "\n"
                    . "👤 <b>Masʼul:</b> "
                    . e($this->task->assignee?->full_name ?? '—')
                    . "\n\n"
                    . "Vazifani boshlash uchun "
                    . "<b>Ishni boshlash</b> tugmasini bosing.";
            }

            return
                "📋 <b>Vazifa qabul qilindi</b>\n\n"
                . "🔢 <b>Raqam:</b> "
                . e($this->task->task_number)
                . "\n"
                . "📌 <b>Vazifa:</b> "
                . e($this->task->title)
                . "\n"
                . "👤 <b>Qabul qilgan:</b> "
                . e($this->actor->full_name);
        }

        /*
         * ---------------------------------------------------------
         * IN_PROGRESS
         * ---------------------------------------------------------
         *
         * Assignee can press "Bajarildi".
         */
        if ($this->toStatus === TaskStatus::IN_PROGRESS) {
            return
                "▶️ <b>Ish boshlandi</b>\n\n"
                . "🔢 <b>Raqam:</b> "
                . e($this->task->task_number)
                . "\n"
                . "📌 <b>Vazifa:</b> "
                . e($this->task->title)
                . "\n"
                . "👤 <b>Masʼul:</b> "
                . e($this->task->assignee?->full_name ?? 'Nomaʼlum')
                . "\n\n"
                . "Vazifani tugatganingizdan so‘ng "
                . "<b>✅ Bajarildi</b> tugmasini bosing.";
        }

        /*
         * ---------------------------------------------------------
         * IN_PROGRESS -> AWAITING_ACCEPTANCE
         * ---------------------------------------------------------
         *
         * Assignee completed the task.
         *
         * Assignor gets:
         *   [Qabul qilish] [Qaytarish]
         */
        if ($this->toStatus === TaskStatus::AWAITING_ACCEPTANCE) {
            return
                "📋 <b>Vazifa bajarildi</b>\n\n"
                . "🔢 <b>Raqam:</b> "
                . e($this->task->task_number)
                . "\n"
                . "📌 <b>Vazifa:</b> "
                . e($this->task->title)
                . "\n"
                . "👤 <b>Bajargan:</b> "
                . e($this->actor->full_name)
                . "\n\n"
                . "📝 <b>Izoh:</b>\n"
                . e($this->message ?: '—');
        }

        /*
         * ---------------------------------------------------------
         * CLOSED
         * ---------------------------------------------------------
         */
        if ($this->toStatus === TaskStatus::CLOSED) {
            return
                "🔒 <b>Vazifa yopildi</b>\n\n"
                . "🔢 <b>Raqam:</b> "
                . e($this->task->task_number)
                . "\n"
                . "📌 <b>Vazifa:</b> "
                . e($this->task->title)
                . "\n"
                . "👤 <b>Masʼul:</b> "
                . e($this->task->assignee?->full_name ?? '—')
                . "\n\n"
                . "Vazifa muvaffaqiyatli yakunlandi.";
        }

        /*
         * ---------------------------------------------------------
         * FALLBACK
         * ---------------------------------------------------------
         */
        return
            "📋 <b>Vazifa statusi o‘zgardi</b>\n\n"
            . "🔢 <b>Raqam:</b> "
            . e($this->task->task_number)
            . "\n"
            . "📌 <b>Vazifa:</b> "
            . e($this->task->title)
            . "\n"
            . "👤 <b>Kim:</b> "
            . e($this->actor->full_name)
            . "\n"
            . "📊 <b>Status:</b> "
            . e($this->fromStatus->label())
            . " → "
            . e($this->toStatus->label());
    }

    public function toTelegramAttachments(Staff $notifiable): array
    {
        if ($this->toStatus !== TaskStatus::AWAITING_ACCEPTANCE
            || $this->task->assignor_id !== $notifiable->id) {
            return [];
        }

        return data_get($this->metadata, 'completion_attachments', []);
    }

    /**
     * Persist Telegram message IDs for completion media sent to the assignor.
     *
     * These IDs are later used by the Qaytarish action to remove the media
     * messages from the assignor's Telegram chat.
     */
    public function recordTelegramAttachmentMessageIds(
        Staff $notifiable,
        array $messageIds,
    ): void {
        if ($this->toStatus !== TaskStatus::AWAITING_ACCEPTANCE
            || $this->task->assignor_id !== $notifiable->id
            || $messageIds === []
        ) {
            return;
        }

        $task = Task::query()->find($this->task->id);

        if (! $task) {
            return;
        }

        $metadata = $task->metadata ?? [];
        $existing = data_get($metadata, 'completion_notification_media_message_ids', []);

        if (! is_array($existing)) {
            $existing = [];
        }

        $metadata['completion_notification_media_message_ids'] = array_values(
            array_unique(array_merge($existing, array_map('intval', $messageIds)))
        );

        $task->update(['metadata' => $metadata]);
    }

    public function toTelegramMarkup(Staff $notifiable): ?array
    {
        /*
        * ASSIGNEE:
        *
        * ACCEPTED -> IN_PROGRESS
        */
        if (
            $this->toStatus === TaskStatus::ACCEPTED
            && $this->task->assignee_id === $notifiable->id
        ) {
            return [
                'inline_keyboard' => [[
                    [
                        'text' => '▶️ Ishni boshlash',
                        'callback_data' =>
                            "task:status:{$this->task->id}:in_progress",
                    ],
                ]],
            ];
        }

        /*
        * ASSIGNEE:
        *
        * IN_PROGRESS -> AWAITING_ACCEPTANCE
        */
        if (
            $this->toStatus === TaskStatus::IN_PROGRESS
            && $this->task->assignee_id === $notifiable->id
        ) {
            return [
                'inline_keyboard' => [[
                    [
                        'text' => '✅ Bajarildi',
                        'callback_data' =>
                            "task:status:{$this->task->id}:awaiting_acceptance",
                    ],
                ]],
            ];
        }

        /*
        * ASSIGNOR:
        *
        * AWAITING_ACCEPTANCE
        *
        * The assignee has completed the task.
        *
        * Assignor can:
        *   1. Accept -> ACCEPTED
        *   2. Return -> IN_PROGRESS
        */
        if (
            $this->toStatus === TaskStatus::AWAITING_ACCEPTANCE
            && $this->task->assignor_id === $notifiable->id
        ) {
            return [
                'inline_keyboard' => [[
                    [
                        'text' => '✅ Tasdiqlash',
                        'callback_data' =>
                            "task:status:{$this->task->id}:completion_approved",
                    ],
                    [
                        'text' => '↩️ Qaytarish',
                        'callback_data' =>
                            "task:status:{$this->task->id}:in_progress",
                    ],
                ]],
            ];
        }

        /*
        * ASSIGNOR:
        *
        * After accepting the completed task:
        *
        * ACCEPTED -> CLOSED
        *
        * Show the final "Yopish" button.
        */
        if (
            $this->toStatus === TaskStatus::COMPLETION_APPROVED
            && $this->task->assignor_id === $notifiable->id
            && $this->task->assignee_id !== $notifiable->id
        ) {
            return [
                'inline_keyboard' => [[
                    [
                        'text' => '🔒 Yopish',
                        'callback_data' =>
                            "task:status:{$this->task->id}:closed",
                    ],
                ]],
            ];
        }

        return null;
    }
}
