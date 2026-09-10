<?php

namespace App\Jobs\Task;

use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\TaskCreationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyTaskRecipients implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
        public readonly int $creatorId,
        public readonly int $chatId,
    ) {
    }

    public function handle(
        TaskCreationService $taskCreationService,
    ): void {
        $task = Task::query()
            ->with(['assignee', 'assignor'])
            ->find($this->taskId);

        $creator = Staff::query()->find($this->creatorId);

        if (! $task || ! $creator) {
            return;
        }

        $taskCreationService->notifyRecipients(
            task: $task,
            creator: $creator,
            chatId: $this->chatId,
        );
    }
}
