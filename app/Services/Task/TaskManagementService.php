<?php

namespace App\Services\Task;

use App\Enums\Permission;
use App\Enums\TaskAssignmentType;
use App\Enums\TaskLogEventType;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskTelegramMessage;
use App\Telegram\Services\TelegramClient;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\TashkentDateTime;

class TaskManagementService
{
    public function __construct(
        private readonly TaskCommentService $comments,
        private readonly TelegramClient $telegram,
    ) {}

    private function requirePermission(Staff $staff, Permission $permission): void
    {
        if (! $staff->can($permission->value)) {
            throw new DomainException('Bu amal uchun ruxsatingiz yo\'q.');
        }
    }

    public function taskForViewer(int $taskId, Staff $viewer): ?Task
    {
        $this->requirePermission($viewer, Permission::TaskView);
        return Task::query()
            ->with(['assignee', 'assignor', 'author'])
            ->whereKey($taskId)
            ->where(function ($query) use ($viewer) {
                $query->where('assignee_id', $viewer->id)
                    ->orWhere('assignor_id', $viewer->id)
                    ->orWhere('author_id', $viewer->id);
            })
            ->first();
    }

    public function changeDeadline(Task $task, Staff $actor, int $minutes): Task
    {
        $this->requirePermission($actor, Permission::TaskUpdate);
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi muddatni o‘zgartirishi mumkin.');
        }
        if ($minutes < 60 || $minutes > 43200) {
            throw new DomainException('Noto‘g‘ri muddat tanlandi.');
        }

        return DB::transaction(function () use ($task, $actor, $minutes) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $old = $locked->deadline;
            $base = $old ? $old->copy() : now();
            $new = $base->addMinutes($minutes);
            $locked->update(['deadline' => $new]);
            $locked->logs()->create([
                'actor_id' => $actor->id,
                'event_type' => TaskLogEventType::DEADLINE_CHANGED,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'message' => 'Deadline changed by assignor.',
                'metadata' => ['old_deadline' => $old?->toIso8601String(), 'new_deadline' => $new->toIso8601String()],
            ]);
            return $locked->fresh(['assignee', 'assignor']);
        });
    }

    public function updateField(Task $task, Staff $actor, string $field, string $value): Task
    {
        $this->requirePermission($actor, Permission::TaskUpdate);
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi tahrirlashi mumkin.');
        }
        if (! in_array($field, ['title', 'description'], true)) {
            throw new DomainException('Tahrirlash maydoni noto‘g‘ri.');
        }
        $value = trim($value);
        if ($value === '') {
            throw new DomainException('Bo‘sh qiymat yuborib bo‘lmaydi.');
        }
        return DB::transaction(function () use ($task, $actor, $field, $value) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $old = $locked->{$field};
            $locked->update([$field => $value]);
            $locked->logs()->create([
                'actor_id' => $actor->id,
                'event_type' => TaskLogEventType::STATUS_CHANGED,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'message' => "Task {$field} updated.",
                'metadata' => ['field' => $field, 'old' => $old, 'new' => $value],
            ]);
            return $locked->fresh(['assignee', 'assignor']);
        });
    }

    public function reassign(Task $task, Staff $actor, Staff $newAssignee): array
    {
        $this->requirePermission($actor, Permission::TaskReassign);
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi qayta biriktirishi mumkin.');
        }
        if ($task->status->isFinal() || in_array($task->status, [TaskStatus::AWAITING_ACCEPTANCE, TaskStatus::COMPLETION_APPROVED], true)) {
            throw new DomainException('Bu holatdagi vazifani qayta biriktirib bo‘lmaydi.');
        }
        if ($newAssignee->id === $actor->id) {
            throw new DomainException('Vazifani o‘zingizga qayta biriktira olmaysiz.');
        }
        if (! $newAssignee->isActive() || ! $newAssignee->telegram_chat_id) {
            throw new DomainException('Tanlangan xodim faol Telegram foydalanuvchisi emas.');
        }
        $result = DB::transaction(function () use ($task, $actor, $newAssignee) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->assignee_id === $newAssignee->id) {
                throw new DomainException('Vazifa allaqachon shu xodimga biriktirilgan.');
            }
            $oldId = $locked->assignee_id;
            $old = $oldId ? Staff::find($oldId) : null;
            $locked->update([
                'assignee_id' => $newAssignee->id,
                'assignment_type' => TaskAssignmentType::DIRECT,
                'status' => TaskStatus::ASSIGNED,
                'started_at' => null,
            ]);
            $locked->logs()->create([
                'actor_id' => $actor->id,
                'event_type' => TaskLogEventType::REASSIGNED,
                'from_status' => $task->status,
                'to_status' => TaskStatus::ASSIGNED,
                'from_assignee_id' => $oldId,
                'to_assignee_id' => $newAssignee->id,
                'message' => "Task reassigned to {$newAssignee->full_name}.",
            ]);
            return [$locked->fresh(['assignee', 'assignor']), $old];
        });
        return ['task' => $result[0], 'old_assignee' => $result[1]];
    }

    public function cancel(Task $task, Staff $actor): Task
    {
        $this->requirePermission($actor, Permission::TaskCancel);
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi bekor qilishi mumkin.');
        }
        if ($task->status->isFinal()) {
            throw new DomainException('Yakunlangan vazifani bekor qilib bo‘lmaydi.');
        }
        return DB::transaction(function () use ($task, $actor) {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $old = $locked->status;
            $locked->update(['status' => TaskStatus::CANCELLED]);
            $locked->logs()->create([
                'actor_id' => $actor->id,
                'event_type' => TaskLogEventType::CANCELLED,
                'from_status' => $old,
                'to_status' => TaskStatus::CANCELLED,
                'message' => 'Task cancelled by assignor.',
            ]);
            return $locked->fresh(['assignee', 'assignor']);
        });
    }

    public function addComment(Task $task, Staff $staff, string $body): TaskComment
    {
        $this->requirePermission($staff, Permission::TaskCommentCreate);
        if (! in_array($staff->id, array_filter([$task->assignee_id, $task->assignor_id, $task->author_id]), true)) {
            throw new DomainException('Bu vazifaga izoh qo‘shish huquqingiz yo‘q.');
        }
        $comment = $this->comments->create($task, $staff, $body);
        $task->logs()->create([
            'actor_id' => $staff->id,
            'event_type' => TaskLogEventType::COMMENTED,
            'from_status' => $task->status,
            'to_status' => $task->status,
            'message' => 'Task comment added.',
        ]);
        return $comment;
    }

    public function addCommentWithAttachments(Task $task, Staff $staff, string $body, array $attachments): TaskComment
    {
        $this->requirePermission($staff, Permission::TaskCommentCreate);
        return DB::transaction(function () use ($task, $staff, $body, $attachments) {
            $comment = $this->addComment($task, $staff, $body);
            foreach ($attachments as $attachment) {
                $comment->attachments()->create($attachment);
            }
            return $comment;
        });
    }

    /**
     * Shared notification payload so management notifications always identify
     * the exact task and include the same core details.
     */
    public function taskDetailsText(Task $task): string
    {
        $task->loadMissing(['assignee', 'assignor', 'author']);

        return "🔢 <b>Raqam:</b> {$task->task_number}\n"
            . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
            . "📝 <b>Tavsif:</b> " . e($task->description ?: '—') . "\n"
            . "👤 <b>Bajaruvchi:</b> " . e($task->assignee?->full_name ?: '—') . "\n"
            . "👤 <b>Beruvchi:</b> " . e($task->assignor?->full_name ?: '—') . "\n"
            . "✍️ <b>Muallif:</b> " . e($task->author?->full_name ?: '—') . "\n"
            . "📊 <b>Holat:</b> " . e($task->status->label()) . "\n"
            . "⚡ <b>Muhimlik:</b> " . e($task->priority?->label() ?: '—') . "\n"
            . "📅 <b>Muddat:</b> " . (TashkentDateTime::format($task->deadline));
    }

    public function sendReminder(Task $task, Staff $actor): void
    {
        $this->requirePermission($actor, Permission::TaskUpdate);
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi eslatma yuborishi mumkin.');
        }

        $recipients = $this->notificationRecipients($task);
        if ($recipients->isEmpty()) {
            Log::warning('sendReminder: no recipients resolved.', [
                'task_id' => $task->id,
                'assignment_type' => $task->assignment_type?->value,
                'assignee_id' => $task->assignee_id,
            ]);

            throw new DomainException('Vazifa bajaruvchisining Telegram chati topilmadi.');
        }

        $this->broadcast(
            $task,
            $recipients,
            "🔔 <b>Vazifa eslatmasi</b>\n\n" . $this->taskDetailsText($task),
        );

        $task->logs()->create([
            'actor_id' => $actor->id,
            'event_type' => TaskLogEventType::STATUS_CHANGED,
            'from_status' => $task->status,
            'to_status' => $task->status,
            'message' => "Reminder sent to {$recipients->count()} recipient(s).",
        ]);
    }

    public function notifyAssigneeOfChange(Task $task, string $heading, string $extra = ''): void
    {
        $recipients = $this->notificationRecipients($task);
        if ($recipients->isEmpty()) {
            Log::warning('notifyAssigneeOfChange: no recipients resolved, nothing sent.', [
                'task_id' => $task->id,
                'heading' => $heading,
                'assignment_type' => $task->assignment_type?->value,
                'assignee_id' => $task->assignee_id,
            ]);

            return;
        }

        $text = "{$heading}\n\n" . $this->taskDetailsText($task);
        if ($extra !== '') {
            $text .= "\n\n{$extra}";
        }

        $this->broadcast($task, $recipients, $text);
    }

    /**
     * Every {staff_id, chat_id} pair that should currently hear about this
     * task.
     *
     * Once a task has an assignee, only they need to know. An unaccepted
     * GROUP task has no assignee yet, so everyone who received the original
     * broadcast (tracked via TaskTelegramMessage rows) must be told instead
     * — otherwise follow-up edits, deadline changes, reminders, and
     * cancellations silently reach nobody.
     */
    private function notificationRecipients(Task $task): Collection
    {
        if ($task->assignee?->telegram_chat_id) {
            Log::info('notificationRecipients: resolved via assignee.', [
                'task_id' => $task->id,
                'assignee_id' => $task->assignee->id,
            ]);

            return collect([[
                'staff_id' => $task->assignee->id,
                'chat_id' => (int) $task->assignee->telegram_chat_id,
            ]]);
        }

        if ($task->assignment_type !== TaskAssignmentType::GROUP) {
            Log::warning('notificationRecipients: no assignee and not a GROUP task, resolved 0 recipients.', [
                'task_id' => $task->id,
                'assignment_type' => $task->assignment_type?->value,
            ]);

            return collect();
        }

        // Only rows for the actual per-candidate broadcast ('notification')
        // and prior follow-up notices ('update') represent real candidates.
        // Other roles ('task_created_notification', 'original', 'context',
        // 'task_update', ...) point at unrelated messages — the group's
        // announcement, the triggering user message, etc. — not candidates.
        $recipients = TaskTelegramMessage::query()
            ->where('task_id', $task->id)
            ->whereIn('role', ['notification', 'update'])
            ->get(['staff_id', 'chat_id'])
            ->map(fn ($row) => [
                'staff_id' => $row->staff_id,
                'chat_id' => (int) $row->chat_id,
            ])
            ->unique('chat_id')
            ->values();

        Log::info('notificationRecipients: resolved via TaskTelegramMessage (unaccepted GROUP task).', [
            'task_id' => $task->id,
            'recipient_count' => $recipients->count(),
        ]);

        return $recipients;
    }

    /**
     * Send the same message to one or more recipients, without letting one
     * recipient's failure (blocked bot, deleted chat, etc.) stop the rest.
     *
     * While a GROUP task is still open to more than one candidate, each
     * delivered message is tracked as a "update" TaskTelegramMessage row so
     * that once someone accepts, everyone else's copy can be cleaned up —
     * see TaskStatusCallback::deleteGroupTaskMessages().
     */
    private function broadcast(Task $task, Collection $recipients, string $text): void
    {
        $isGroupBroadcast = $recipients->count() > 1;
        $delivered = 0;

        foreach ($recipients as $recipient) {
            try {
                $response = $this->telegram->sendMessage(
                    $recipient['chat_id'],
                    $text,
                    parseMode: 'HTML',
                );

                $delivered++;
            } catch (\Throwable $e) {
                Log::warning('Failed to deliver task notification.', [
                    'task_id' => $task->id,
                    'staff_id' => $recipient['staff_id'] ?? null,
                    'chat_id' => $recipient['chat_id'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $isGroupBroadcast || ! $recipient['staff_id']) {
                continue;
            }

            $messageId = $response['result']['message_id'] ?? null;

            if ($messageId) {
                TaskTelegramMessage::create([
                    'task_id' => $task->id,
                    'staff_id' => $recipient['staff_id'],
                    'chat_id' => $recipient['chat_id'],
                    'message_id' => $messageId,
                    'role' => 'update',
                ]);
            }
        }

        Log::info('broadcast: delivery summary.', [
            'task_id' => $task->id,
            'recipient_count' => $recipients->count(),
            'delivered_count' => $delivered,
            'is_group_broadcast' => $isGroupBroadcast,
        ]);
    }


    public function cleanupTaskNotificationMessages(Task $task): void
    {
        // Only the original per-candidate "tap to accept" broadcast
        // messages (role: 'notification') are stale action prompts that
        // belong here. Follow-up "update" notices, the group's "task
        // created" announcement ('task_created_notification'), and
        // message-context links ('original', 'context', 'task_update')
        // are historical record and must stay visible.
        $messages = TaskTelegramMessage::query()
            ->where('task_id', $task->id)
            ->where('role', 'notification')
            ->get();
        foreach ($messages as $message) {
            try {
                $this->telegram->deleteMessage($message->chat_id, $message->message_id);
            } catch (\Throwable) {
                // Telegram messages can already be deleted or too old to remove.
            }
            $message->delete();
        }
    }

    public function notifyNewAssignee(Task $task): void
    {
        $task->loadMissing(['assignee','assignor']);
        if (! $task->assignee?->telegram_chat_id) return;
        $response = $this->telegram->sendMessage(
            $task->assignee->telegram_chat_id,
            "📋 <b>Yangi vazifa</b>\n\n" . $this->taskDetailsText($task),
            ['inline_keyboard'=>[[['text'=>'✅ Qabul qilish','callback_data'=>"task:status:{$task->id}:accepted"]]]],
            'HTML',
        );
        $messageId = $response['result']['message_id'] ?? null;
        if ($messageId) TaskTelegramMessage::create(['task_id'=>$task->id,'staff_id'=>$task->assignee->id,'chat_id'=>$task->assignee->telegram_chat_id,'message_id'=>$messageId]);
    }
}
