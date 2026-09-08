<?php

namespace App\Telegram\Callbacks;

use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskPostponeRequest;
use App\Services\Task\TaskPostponeService;
use App\Telegram\Services\TelegramClient;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

use App\Support\TashkentDateTime;

class TaskPostponeCallback
{
    public function __construct(
        private readonly TaskPostponeService $postponeService,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function selectTask(
        Staff $staff,
        int $taskId,
        string $callbackQueryId,
        int $chatId,
        int $messageId,
    ): void {
        $task = Task::query()->find($taskId);

        if (
            ! $task
            || $task->assignee_id !== $staff->id
            || ! $task->deadline
            || ! in_array($task->status, [
                TaskStatus::ASSIGNED,
                TaskStatus::ACCEPTED,
                TaskStatus::IN_PROGRESS,
            ], true)
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Bu vazifa uchun muddatni kechiktirish so‘ralmaydi.'
            );

            return;
        }

        $this->telegram->answerCallbackQuery($callbackQueryId, 'Muddatni tanlang.');
        $this->deleteMessageQuietly($chatId, $messageId);

        $this->telegram->sendMessage(
            chatId: $chatId,
            text: "⏳ <b>Muddatni kechiktirish</b>\n\n"
                . "🔢 <b>Raqam:</b> {$task->task_number}\n"
                . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
                . "📅 <b>Hozirgi muddat:</b> " . TashkentDateTime::format($task->deadline) . "\n\n"
                . "Qancha vaqtga kechiktirishni tanlang:",
            replyMarkup: [
                'inline_keyboard' => [
                    [
                        ['text' => '⏱ 1 soat', 'callback_data' => "postpone:duration:{$task->id}:60"],
                        ['text' => '⏱ 2 soat', 'callback_data' => "postpone:duration:{$task->id}:120"],
                    ],
                    [
                        ['text' => '⏱ 4 soat', 'callback_data' => "postpone:duration:{$task->id}:240"],
                        ['text' => '⏱ 8 soat', 'callback_data' => "postpone:duration:{$task->id}:480"],
                    ],
                    [
                        ['text' => '📅 1 kun', 'callback_data' => "postpone:duration:{$task->id}:1440"],
                        ['text' => '📅 2 kun', 'callback_data' => "postpone:duration:{$task->id}:2880"],
                    ],
                    [
                        ['text' => '📅 3 kun', 'callback_data' => "postpone:duration:{$task->id}:4320"],
                        ['text' => '📅 7 kun', 'callback_data' => "postpone:duration:{$task->id}:10080"],
                    ],
                ],
            ],
            parseMode: 'HTML',
        );
    }

    public function request(
        Staff $staff,
        int $taskId,
        int $minutes,
        string $callbackQueryId,
        int $chatId,
        int $messageId,
    ): void {
        try {
            $task = Task::query()->with('assignor')->find($taskId);

            if (! $task) {
                throw new DomainException('Vazifa topilmadi.');
            }

            [$amount, $unit] = $this->durationFromMinutes($minutes);

            $request = $this->postponeService->request(
                task: $task,
                requester: $staff,
                amount: $amount,
                unit: $unit,
            );

            $this->telegram->answerCallbackQuery($callbackQueryId, 'So‘rov yuborildi.');
            $this->deleteMessageQuietly($chatId, $messageId);

            $this->telegram->sendMessage(
                $chatId,
                "⏳ <b>Muddatni kechiktirish so‘rovi yuborildi</b>\n\n"
                . "🔢 <b>Raqam:</b> {$task->task_number}\n"
                . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
                . "📅 <b>Yangi so‘ralgan muddat:</b> " . TashkentDateTime::format($request->requested_deadline) . "\n\n"
                . "Vazifa beruvchisining qarorini kuting.",
                parseMode: 'HTML',
            );

            if (! $task->assignor?->telegram_chat_id) {
                return;
            }

            $this->telegram->sendMessage(
                chatId: $task->assignor->telegram_chat_id,
                text: "⏳ <b>Muddatni kechiktirish so‘rovi</b>\n\n"
                    . "🔢 <b>Raqam:</b> {$task->task_number}\n"
                    . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
                    . "👤 <b>So‘rov yuborgan:</b> " . e($staff->full_name) . "\n"
                    . "📅 <b>Hozirgi muddat:</b> " . TashkentDateTime::format($request->old_deadline) . "\n"
                    . "➡️ <b>So‘ralgan muddat:</b> " . TashkentDateTime::format($request->requested_deadline) . "\n\n"
                    . "So‘rovni ko‘rib chiqing.",
                replyMarkup: [
                    'inline_keyboard' => [[
                        [
                            'text' => '✅ Ruxsat berish',
                            'callback_data' => "postpone:approve:{$request->id}",
                        ],
                        [
                            'text' => '❌ Rad etish',
                            'callback_data' => "postpone:reject:{$request->id}",
                        ],
                    ]],
                ],
                parseMode: 'HTML',
            );
        } catch (DomainException $e) {
            $this->telegram->answerCallbackQuery($callbackQueryId, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            Log::error('Task postpone request failed', ['task_id' => $taskId, 'staff_id' => $staff->id, 'error' => $e->getMessage()]);
            $this->telegram->answerCallbackQuery($callbackQueryId, '❌ So‘rovni yuborishda xatolik yuz berdi.');
        }
    }

    public function decide(
        Staff $staff,
        int $requestId,
        bool $approved,
        string $callbackQueryId,
        int $chatId,
        int $messageId,
    ): void {
        try {
            $request = TaskPostponeRequest::query()->with(['task', 'requester'])->find($requestId);

            if (! $request) {
                throw new DomainException('So‘rov topilmadi.');
            }

            $request = $approved
                ? $this->postponeService->approve($request, $staff)
                : $this->postponeService->reject($request, $staff);

            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                $approved ? 'Muddat kechiktirildi.' : 'So‘rov rad etildi.',
            );

            $this->deleteMessageQuietly($chatId, $messageId);

            $request->loadMissing(['task', 'requester']);

            if ($request->requester?->telegram_chat_id) {
                $text = $approved
                    ? "✅ <b>Muddatni kechiktirish tasdiqlandi</b>\n\n"
                        . "🔢 <b>Raqam:</b> {$request->task->task_number}\n"
                        . "📌 <b>Vazifa:</b> " . e($request->task->title) . "\n"
                        . "📅 <b>Oldingi muddat:</b> " . TashkentDateTime::format($request->old_deadline) . "\n"
                        . "📅 <b>Yangi muddat:</b> " . TashkentDateTime::format($request->requested_deadline)
                    : "❌ <b>Muddatni kechiktirish rad etildi</b>\n\n"
                        . "🔢 <b>Raqam:</b> {$request->task->task_number}\n"
                        . "📌 <b>Vazifa:</b> " . e($request->task->title) . "\n"
                        . "📅 <b>Amaldagi muddat:</b> " . TashkentDateTime::format($request->old_deadline);

                $this->telegram->sendMessage(
                    $request->requester->telegram_chat_id,
                    $text,
                    parseMode: 'HTML',
                );
            }
        } catch (DomainException $e) {
            $this->telegram->answerCallbackQuery($callbackQueryId, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            Log::error('Task postpone decision failed', ['request_id' => $requestId, 'staff_id' => $staff->id, 'error' => $e->getMessage()]);
            $this->telegram->answerCallbackQuery($callbackQueryId, '❌ So‘rovni ko‘rib chiqishda xatolik yuz berdi.');
        }
    }

    private function durationFromMinutes(int $minutes): array
    {
        if ($minutes < 60 || $minutes > 10080) {
            throw new DomainException('Noto‘g‘ri muddat tanlandi.');
        }

        if ($minutes % 1440 === 0) {
            return [intdiv($minutes, 1440), 'days'];
        }

        if ($minutes % 60 === 0) {
            return [intdiv($minutes, 60), 'hours'];
        }

        throw new DomainException('Noto‘g‘ri muddat tanlandi.');
    }

    private function deleteMessageQuietly(int $chatId, int $messageId): void
    {
        try {
            $this->telegram->deleteMessage($chatId, $messageId);
        } catch (Throwable $e) {
            Log::warning('Failed to clean Telegram postpone message.', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
