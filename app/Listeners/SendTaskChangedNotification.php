<?php

namespace App\Listeners;

use App\Events\TaskChanged;
use App\Notifications\TaskChangedNotification;
class SendTaskChangedNotification
{
    public function handle(TaskChanged $event): void
    {
        $task = $event->task->fresh(['assignee', 'assignor']);

        if (! $task) {
            return;
        }

        $recipients = collect([$task->assignee, $task->assignor])
            ->filter()
            ->unique('id')
            ->reject(fn ($staff) => $staff->id === $event->actor->id);

        foreach ($recipients as $recipient) {
            $recipient->notify(new TaskChangedNotification(
                task: $task,
                actor: $event->actor,
                changes: $event->changes,
            ));
        }
    }
}
