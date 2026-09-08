<?php

namespace App\Services\Task\Context;

use App\Models\Staff;

class TaskMessageContextResolver
{
    public function __construct(
        private readonly TaskMessageLinkService $links,
        private readonly TaskCandidateFinder $candidates,
    ) {
    }

    public function resolve(Staff $staff, array $message): array
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $reply = $message['reply_to_message'] ?? null;
        $replyMessageId = isset($reply['message_id']) ? (int) $reply['message_id'] : null;
        $linkedTask = $replyMessageId
            ? $this->links->findTask($chatId, $replyMessageId)
            : null;

        return [
            'is_reply' => $replyMessageId !== null,
            'replied_message' => $reply ? $this->normalizeMessage($reply) : null,
            'reply_task' => $linkedTask ? $this->serializeTask($linkedTask) : null,
            'candidate_tasks' => $linkedTask
                ? []
                : $this->candidates->find($staff, $chatId)->map(fn ($task) => $this->serializeTask($task))->all(),
        ];
    }

    private function normalizeMessage(array $message): array
    {
        return [
            'message_id' => $message['message_id'] ?? null,
            'text' => $message['text'] ?? $message['caption'] ?? null,
            'from_bot' => (bool) ($message['from']['is_bot'] ?? false),
        ];
    }

    private function serializeTask($task): array
    {
        return [
            'id' => $task->id,
            'task_number' => $task->task_number,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority?->value ?? (string) $task->priority,
            'deadline' => $task->deadline?->toIso8601String(),
            'status' => $task->status?->value ?? (string) $task->status,
            'assignee_name' => $task->assignee?->full_name,
        ];
    }
}
