<?php

namespace App\Telegram\Callbacks;

use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Telegram\Services\TelegramClient;

class TaskAcceptCallback
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function handle(
        Staff $staff,
        int $taskId,
        string $callbackQueryId,
        int $chatId,
    ): void {
        try {
            $task = Task::findOrFail(
                $taskId
            );

            $task = $this->taskService->accept(
                task: $task,
                staff: $staff,
            );

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Vazifa qabul qilindi.'
            );

            $this->telegram->sendMessage(
                $chatId,
                "✅ Vazifa sizga biriktirildi.\n\n"
                . "📌 {$task->title}"
            );
        } catch (\Throwable $e) {

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Vazifani qabul qilib bo‘lmadi.'
            );
        }
    }
}
