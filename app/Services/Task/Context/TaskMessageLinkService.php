<?php

namespace App\Services\Task\Context;

use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskTelegramMessage;

class TaskMessageLinkService
{
    public function findTask(int $chatId, int $messageId): ?Task
    {
        return TaskTelegramMessage::query()
            ->where('chat_id', $chatId)
            ->where('message_id', $messageId)
            ->whereIn('role', [
                'original',
                'task_created_notification',
                'context',
            ])
            ->with('task')
            ->latest('id')
            ->first()?->task;
    }

    public function link(Task $task, int $chatId, int $messageId, ?Staff $staff = null, string $role = 'context'): void
    {
        TaskTelegramMessage::query()->updateOrCreate(
            ['chat_id' => $chatId, 'message_id' => $messageId],
            [
                'task_id' => $task->id,
                'staff_id' => $staff?->id,
                'role' => $role,
            ],
        );
    }
}
