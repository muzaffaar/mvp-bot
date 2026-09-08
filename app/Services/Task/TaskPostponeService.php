<?php

namespace App\Services\Task;

use App\Enums\TaskLogEventType;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskPostponeRequest;
use DomainException;
use Illuminate\Support\Facades\DB;

class TaskPostponeService
{
    /**
     * Only these states represent work that can still have its deadline extended.
     */
    private const REQUESTABLE_STATUSES = [
        TaskStatus::ASSIGNED,
        TaskStatus::ACCEPTED,
        TaskStatus::IN_PROGRESS,
    ];

    public function request(
        Task $task,
        Staff $requester,
        int $amount,
        string $unit,
    ): TaskPostponeRequest {
        if ($task->assignee_id !== $requester->id) {
            throw new DomainException('Bu vazifa sizga biriktirilmagan.');
        }

        if (! in_array($task->status, self::REQUESTABLE_STATUSES, true)) {
            throw new DomainException('Bu vazifa uchun muddatni kechiktirish so‘ralmaydi.');
        }

        if (! $task->deadline) {
            throw new DomainException('Bu vazifada muddat belgilanmagan.');
        }

        $minutes = match ($unit) {
            'hours' => $amount * 60,
            'days' => $amount * 24 * 60,
            default => throw new DomainException('Noto‘g‘ri muddat turi.'),
        };

        if ($amount < 1 || $amount > 30 || $minutes > 30 * 24 * 60) {
            throw new DomainException('So‘ralgan muddat ruxsat etilgan chegaradan tashqarida.');
        }

        return DB::transaction(function () use ($task, $requester, $amount, $unit, $minutes) {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            $existing = TaskPostponeRequest::query()
                ->where('task_id', $lockedTask->id)
                ->where('requester_id', $requester->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new DomainException('Bu vazifa uchun allaqachon kutilayotgan so‘rov mavjud.');
            }

            $oldDeadline = $lockedTask->deadline->copy();
            $requestedDeadline = $oldDeadline->copy()->addMinutes($minutes);

            $request = TaskPostponeRequest::query()->create([
                'task_id' => $lockedTask->id,
                'requester_id' => $requester->id,
                'amount' => $amount,
                'unit' => $unit,
                'minutes' => $minutes,
                'old_deadline' => $oldDeadline,
                'requested_deadline' => $requestedDeadline,
                'status' => 'pending',
            ]);

            $lockedTask->logs()->create([
                'actor_id' => $requester->id,
                'event_type' => TaskLogEventType::DEADLINE_CHANGED,
                'message' => 'Muddatni kechiktirish so‘rovi yuborildi.',
                'metadata' => [
                    'action' => 'postpone_requested',
                    'request_id' => $request->id,
                    'old_deadline' => $oldDeadline->toIso8601String(),
                    'requested_deadline' => $requestedDeadline->toIso8601String(),
                    'amount' => $amount,
                    'unit' => $unit,
                ],
            ]);

            return $request->load(['task', 'requester']);
        });
    }

    public function approve(TaskPostponeRequest $request, Staff $assignor): TaskPostponeRequest
    {
        return $this->decide($request, $assignor, true);
    }

    public function reject(TaskPostponeRequest $request, Staff $assignor): TaskPostponeRequest
    {
        return $this->decide($request, $assignor, false);
    }

    private function decide(
        TaskPostponeRequest $request,
        Staff $assignor,
        bool $approved,
    ): TaskPostponeRequest {
        return DB::transaction(function () use ($request, $assignor, $approved) {
            $lockedRequest = TaskPostponeRequest::query()
                ->with('task')
                ->lockForUpdate()
                ->findOrFail($request->id);

            if ($lockedRequest->status !== 'pending') {
                throw new DomainException('Bu so‘rov allaqachon ko‘rib chiqilgan.');
            }

            $task = Task::query()->lockForUpdate()->findOrFail($lockedRequest->task_id);

            if ($task->assignor_id !== $assignor->id) {
                throw new DomainException('Faqat vazifa beruvchisi bu so‘rovni ko‘rib chiqishi mumkin.');
            }

            $oldDeadline = $task->deadline?->copy();

            if ($approved) {
                $task->deadline = $lockedRequest->requested_deadline;
                $task->save();
            }

            $lockedRequest->update([
                'status' => $approved ? 'approved' : 'rejected',
                'decided_by' => $assignor->id,
                'decided_at' => now(),
            ]);

            $task->logs()->create([
                'actor_id' => $assignor->id,
                'event_type' => TaskLogEventType::DEADLINE_CHANGED,
                'message' => $approved
                    ? 'Muddatni kechiktirish so‘rovi tasdiqlandi.'
                    : 'Muddatni kechiktirish so‘rovi rad etildi.',
                'metadata' => [
                    'action' => $approved ? 'postpone_approved' : 'postpone_rejected',
                    'request_id' => $lockedRequest->id,
                    'old_deadline' => $oldDeadline?->toIso8601String(),
                    'requested_deadline' => $lockedRequest->requested_deadline->toIso8601String(),
                ],
            ]);

            return $lockedRequest->fresh(['task', 'requester', 'decider']);
        });
    }
}
