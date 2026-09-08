<?php

namespace App\Services\Task;

use App\Events\TaskChanged;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\Context\TaskMessageLinkService;
use App\Support\TashkentDateTime;
use Illuminate\Support\Facades\DB;

class TaskUpdateService
{
    public function __construct(private readonly TaskMessageLinkService $links)
    {
    }

    public function apply(Task $task, Staff $actor, array $decision, int $chatId, int $messageId): array
    {
        return DB::transaction(function () use ($task, $actor, $decision, $chatId, $messageId) {
            $task->refresh();
            $changes = [];
            $data = [];

            if (($decision['title'] ?? null) && $decision['title'] !== $task->title) {
                $changes[] = ['field' => 'title', 'label' => 'Vazifa', 'old' => $task->title, 'new' => $decision['title']];
                $data['title'] = $decision['title'];
            }

            if (($append = trim((string) ($decision['description_append'] ?? ''))) !== '') {
                $old = $task->description;
                $new = trim(implode("\n\n", array_filter([$old, $append])));
                if ($new !== $old) {
                    $changes[] = ['field' => 'description', 'label' => 'Qo\'shimcha ma\'lumot', 'old' => $old, 'new' => $append];
                    $data['description'] = $new;
                }
            }

            if (! empty($decision['deadline'])) {
                $newDeadline = TashkentDateTime::normalize($decision['deadline']);
                if (! $task->deadline || ! $task->deadline->equalTo($newDeadline)) {
                    $changes[] = ['field' => 'deadline', 'label' => 'Muddat', 'old' => $task->deadline?->format('d.m.Y H:i'), 'new' => $newDeadline?->format('d.m.Y H:i')];
                    $data['deadline'] = $newDeadline;
                }
            }

            if (($priority = $decision['priority'] ?? null) && $priority !== ($task->priority?->value ?? $task->priority)) {
                $changes[] = ['field' => 'priority', 'label' => 'Muhimlik', 'old' => $task->priority?->value ?? (string) $task->priority, 'new' => $priority];
                $data['priority'] = $priority;
            }

            if ($data !== []) {
                $task->update($data);
            }

            $this->links->link($task, $chatId, $messageId, $actor, 'update');

            if ($changes !== []) {
                TaskChanged::dispatch($task->fresh(['assignee', 'assignor']), $actor, $changes);
            }

            return [
                'task' => $task->fresh(['assignee', 'assignor']),
                'changes' => $changes,
            ];
        });
    }
}
