<?php

namespace App\Listeners;

use App\Events\TaskChanged;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskTelegramMessage;
use App\Notifications\TaskChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SendTaskChangedNotification
{
    public function handle(TaskChanged $event): void
    {
        $task = $event->task->fresh(['assignee', 'assignor']);

        if (! $task) {
            return;
        }

        $recipients = $this->resolveRecipients($task)
            ->reject(fn (Staff $staff) => $staff->id === $event->actor->id);

        Log::info('SendTaskChangedNotification: recipients resolved.', [
            'task_id' => $task->id,
            'actor_id' => $event->actor->id,
            'assignee_id' => $task->assignee_id,
            'assignment_type' => $task->assignment_type?->value,
            'recipient_ids' => $recipients->pluck('id')->values()->all(),
        ]);

        foreach ($recipients as $recipient) {
            $recipient->notify(new TaskChangedNotification(
                task: $task,
                actor: $event->actor,
                changes: $event->changes,
            ));
        }
    }

    /**
     * Once a task has an assignee, only they (and the assignor) need to
     * know about a change. An unaccepted GROUP task has no assignee yet,
     * so everyone who received the original broadcast (tracked via
     * TaskTelegramMessage rows) must be told instead — otherwise a
     * follow-up edit made before anyone accepts reaches nobody, and if
     * the assignor themself is the one making the edit, the recipient
     * list used to end up completely empty.
     */
    private function resolveRecipients(Task $task): Collection
    {
        if ($task->assignee) {
            return collect([$task->assignee, $task->assignor])
                ->filter()
                ->unique('id');
        }

        if ($task->assignment_type?->value !== 'group') {
            return collect([$task->assignor])->filter();
        }

        $candidateStaffIds = TaskTelegramMessage::query()
            ->where('task_id', $task->id)
            ->whereIn('role', ['notification', 'update'])
            ->whereNotNull('staff_id')
            ->pluck('staff_id')
            ->unique();

        $candidates = Staff::query()
            ->whereIn('id', $candidateStaffIds)
            ->get();

        return $candidates
            ->push($task->assignor)
            ->filter()
            ->unique('id');
    }
}
