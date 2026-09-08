<?php

namespace App\Notifications;

use App\Models\Staff;
use App\Models\Task;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Task $task,
        public readonly Staff $actor,
        public readonly array $changes,
    ) {
    }

    public function via(Staff $notifiable): array
    {
        return ['database', TelegramChannel::class];
    }

    public function toDatabase(Staff $notifiable): array
    {
        return [
            'type' => 'task_changed',
            'task_id' => $this->task->id,
            'task_number' => $this->task->task_number,
            'title' => $this->task->title,
            'actor_id' => $this->actor->id,
            'changes' => $this->changes,
        ];
    }


    public function taskIdForTelegramLink(): int
    {
        return $this->task->id;
    }

    public function toTelegram(Staff $notifiable): string
    {
        $lines = [];
        foreach ($this->changes as $change) {
            $label = $change['label'] ?? $change['field'] ?? 'O\'zgarish';
            $value = trim((string) ($change['new'] ?? $change['value'] ?? ''));
            $old = $change['old'] ?? null;

            if (($change['mode'] ?? null) === 'append') {
                $lines[] = '🆕 <b>Qo\'shildi:</b> ' . e($value);
                continue;
            }

            $lines[] = $old !== null
                ? '• <b>' . e($label) . ':</b> ' . e((string) $old) . ' → ' . e($value)
                : '• <b>' . e($label) . ':</b> ' . e($value);
        }

        return "🔄 <b>Vazifa yangilandi</b>\n\n"
            . "🔢 <b>Raqam:</b> " . e($this->task->task_number)
            . "\n📌 <b>Vazifa:</b> " . e($this->task->title)
            . "\n\n<b>O'zgarishlar:</b>\n"
            . implode("\n", $lines);
    }
}
