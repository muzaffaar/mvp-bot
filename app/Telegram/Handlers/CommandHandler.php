<?php

namespace App\Telegram\Handlers;

use App\Telegram\Services\TelegramClient;
use App\Telegram\Services\TelegramStaffResolver;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Telegram\Callbacks\TaskManagementCallback;

use App\Support\TashkentDateTime;

class CommandHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramStaffResolver $staffResolver,
        private readonly TaskManagementCallback $taskManagement,
    ) {
    }

    public function handle(array $message): void
    {
        $staff = $this->staffResolver->resolve(
            $message
        );

        $text = trim(
            $message['text'] ?? ''
        );

        $command = strtolower(
            explode(
                ' ',
                $text
            )[0]
        );

        $chatId = $message['chat']['id'];

        match ($command) {
            '/start' =>
                $this->start($chatId),

            '/help' =>
                $this->help($chatId),

            '/cancel' =>
                $this->cancel(
                    $chatId,
                    $staff
                ),

            '/postpone', '/kechiktirish' =>
                $this->postpone(
                    $chatId,
                    $staff
                ),

            default =>
                $this->telegram->sendMessage(
                    $chatId,
                    'Nomaʼlum buyruq. /help buyrug‘idan foydalaning.'
                ),
        };
    }

    private function start(
        int $chatId
    ): void {
        $this->telegram->sendMessage(
            $chatId,
            "Assalomu alaykum.\n\n"
            . "Vazifa boshqaruv tizimiga xush kelibsiz.\n"
            . "Oddiy tilda vazifa yaratishingiz mumkin.\n\n"
            . "Masalan:\n"
            . "“IT guruhidagi Aliga serverni tekshirish vazifasini ber.”",
            $this->mainKeyboard(),
        );
    }

    private function help(
        int $chatId
    ): void {
        $this->telegram->sendMessage(
            $chatId,
            "/start — tizimni boshlash\n"
            . "/help — yordam\n"
            . "/cancel — joriy operatsiyani bekor qilish\n"
            . "/postpone — muddatni kechiktirishni so‘rash\n\n"
            . "📋 Vazifalarni boshqarish tugmasi orqali vazifalaringizni boshqaring.\n\n"
            . "Vazifalarni oddiy tilda yuborishingiz mumkin."
        );
    }

    private function cancel(
        int $chatId,
        \App\Models\Staff $staff
    ): void {
        // Conversation reset will be added here.
        $this->telegram->sendMessage(
            $chatId,
            'Joriy operatsiya bekor qilindi.'
        );
    }

    public function handleManagementButton(int $chatId, \App\Models\Staff $staff): void
    {
        $this->manage($chatId, $staff);
    }

    private function manage(int $chatId, \App\Models\Staff $staff): void
    {
        // Task management is intentionally private-chat only. Group chats must
        // never become an entry point for browsing or managing task data.
        // For private Telegram chats the chat ID is the user's Telegram ID.
        if ((string) $chatId !== (string) $staff->telegram_chat_id) {
            $this->telegram->sendMessage(
                $chatId,
                '🔒 Vazifalarni boshqarish faqat bot bilan shaxsiy chatda ishlaydi.'
            );

            return;
        }

        $this->taskManagement->sendHome($chatId, $staff);
    }

    private function postpone(
        int $chatId,
        \App\Models\Staff $staff
    ): void {
        $tasks = Task::query()
            ->where('assignee_id', $staff->id)
            ->whereNotNull('deadline')
            ->whereIn('status', [
                TaskStatus::ASSIGNED->value,
                TaskStatus::ACCEPTED->value,
                TaskStatus::IN_PROGRESS->value,
            ])
            ->orderBy('deadline')
            ->limit(20)
            ->get();

        if ($tasks->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                '⏳ Muddatni kechiktirish uchun sizga biriktirilgan, qabul qilingan yoki jarayondagi muddatli vazifa topilmadi.'
            );

            return;
        }

        $keyboard = $tasks
            ->map(function (Task $task): array {
                $title = trim((string) $task->title);
                $shortTitle = mb_strimwidth($title, 0, 36, '…');
                $deadline = TashkentDateTime::short($task->deadline);

                return [[
                    'text' => "{$task->task_number} · {$shortTitle} · {$deadline}",
                    'callback_data' => "postpone:task:{$task->id}",
                ]];
            })
            ->all();

        $this->telegram->sendMessage(
            chatId: $chatId,
            text: "⏳ <b>Muddatni kechiktirish</b>\n\n"
                . "Quyidagi vazifalardan birini tanlang. Faqat sizga biriktirilgan va <b>Assigned / Accepted / In progress</b> holatidagi muddatli vazifalar ko‘rsatiladi.",
            replyMarkup: [
                'inline_keyboard' => $keyboard,
            ],
            parseMode: 'HTML',
        );
    }
    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => '📋 Vazifalarni boshqarish'],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'one_time_keyboard' => false,
        ];
    }
}
