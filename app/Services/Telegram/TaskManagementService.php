<?php

namespace App\Services\Task;

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
use Illuminate\Support\Facades\DB;

class TaskManagementService
{
    public function __construct(
        private readonly TaskCommentService $comments,
        private readonly TelegramClient $telegram,
    ) {}

    public function taskForViewer(int $taskId, Staff $viewer): ?Task
    {
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
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi qayta biriktirishi mumkin.');
        }
        if ($task->status->isFinal() || in_array($task->status, [TaskStatus::AWAITING_ACCEPTANCE, TaskStatus::COMPLETION_APPROVED], true)) {
            throw new DomainException('Bu holatdagi vazifani qayta biriktirib bo‘lmaydi.');
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

    public function sendReminder(Task $task, Staff $actor): void
    {
        if ($task->assignor_id !== $actor->id) {
            throw new DomainException('Faqat vazifa beruvchisi eslatma yuborishi mumkin.');
        }
        if (! $task->assignee?->telegram_chat_id) {
            throw new DomainException('Vazifa bajaruvchisining Telegram chati topilmadi.');
        }
        $this->telegram->sendMessage($task->assignee->telegram_chat_id,
            "🔔 <b>Vazifa eslatmasi</b>\n\n"
            . "🔢 <b>Raqam:</b> {$task->task_number}\n"
            . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
            . "📊 <b>Holat:</b> {$task->status->label()}\n"
            . "📅 <b>Muddat:</b> " . ($task->deadline?->format('d.m.Y H:i') ?? 'Belgilanmagan'),
            parseMode: 'HTML');
        $task->logs()->create(['actor_id'=>$actor->id,'event_type'=>TaskLogEventType::STATUS_CHANGED,'from_status'=>$task->status,'to_status'=>$task->status,'message'=>'Reminder sent to assignee.']);
    }

    public function notifyAssigneeOfChange(Task $task, string $heading, string $extra = ''): void
    {
        if (! $task->assignee?->telegram_chat_id) return;
        $this->telegram->sendMessage($task->assignee->telegram_chat_id,
            "{$heading}\n\n🔢 <b>Raqam:</b> {$task->task_number}\n📌 <b>Vazifa:</b> " . e($task->title) . ($extra ? "\n{$extra}" : ''),
            parseMode: 'HTML');
    }


    public function cleanupTaskNotificationMessages(Task $task): void
    {
        $messages = TaskTelegramMessage::query()->where('task_id', $task->id)->get();
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
        $response = $this->telegram->sendMessage($task->assignee->telegram_chat_id,
            "📋 <b>Yangi vazifa</b>\n\n"
            . "🔢 <b>Raqam:</b> {$task->task_number}\n"
            . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
            . "📝 <b>Tavsif:</b> " . e($task->description ?: '—') . "\n"
            . "👤 <b>Beruvchi:</b> " . e($task->assignor?->full_name ?: '—') . "\n"
            . "⏰ <b>Muddat:</b> " . ($task->deadline?->format('d.m.Y H:i') ?? 'Belgilanmagan'),
            ['inline_keyboard'=>[[['text'=>'✅ Qabul qilish','callback_data'=>"task:status:{$task->id}:accepted"]]]], 'HTML');
        $messageId = $response['result']['message_id'] ?? null;
        if ($messageId) TaskTelegramMessage::create(['task_id'=>$task->id,'staff_id'=>$task->assignee->id,'chat_id'=>$task->assignee->telegram_chat_id,'message_id'=>$messageId]);
    }
}
