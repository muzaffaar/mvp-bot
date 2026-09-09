<?php

namespace App\Telegram\Callbacks;

use App\AI\Services\ConversationService;
use App\Enums\Permission;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\Context\TaskMessageLinkService;
use App\Services\Task\TaskCreationService;
use App\Telegram\Enums\ConversationState;
use App\Telegram\Services\TelegramClient;

use App\Support\TashkentDateTime;

class AssigneeSelectionCallback
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly TelegramClient $telegram,
        private readonly TaskCreationService $taskCreationService,
        private readonly TaskMessageLinkService $messageLinks,
    ) {
    }

    public function handle(
        Staff $staff,
        int $chatId,
        int $messageId,
        string $callbackQueryId,
        int $assigneeId,
    ): void {

        if (! $staff->can(Permission::TaskAssign->value)) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                '❌ Sizda vazifani xodimga biriktirish huquqi yo‘q.',
            );

            return;
        }

        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $state = $this->conversationService->state(
            $conversation,
        );

        if (
            $state !== ConversationState::AWAITING_ASSIGNEE
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Xodim tanlash holati faol emas.',
            );

            return;
        }

        $context = $this->conversationService->context(
            $conversation,
        );

        /*
         * The conversation row is keyed by Telegram chat, not by staff,
         * so a group with more than one admin shares a single pending
         * selection. If another admin's message has since started a
         * different pending selection (or this one was never theirs),
         * reject the click instead of silently applying the wrong
         * staff member to a task context that isn't this admin's.
         */
        $requestedByStaffId = $context['assignee_selection']['requested_by_staff_id']
            ?? null;

        if (
            $requestedByStaffId !== null
            && (int) $requestedByStaffId !== $staff->id
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Bu tanlov boshqa foydalanuvchi uchun yaratilgan.',
            );

            return;
        }

        /*
         * Only allow selecting a staff member that was actually
         * presented in the candidate list.
         */
        $candidateIds = $context['assignee_selection']['candidate_ids']
            ?? [];

        $candidateIds = array_map(
            'intval',
            $candidateIds,
        );

        if (
            ! in_array(
                $assigneeId,
                $candidateIds,
                true,
            )
        ) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Bu xodim tanlovda mavjud emas.',
            );

            return;
        }

        $assignee = Staff::query()->find(
            $assigneeId,
        );

        if (! $assignee) {
            $this->telegram->answerCallbackQuery(
                $callbackQueryId,
                'Xodim topilmadi.',
            );

            return;
        }

        /*
         * Finalize assignment.
         */
        $context['assignment_type'] = 'direct';
        $context['open_assignment'] = false;
        $context['assignee_id'] = $assignee->id;
        $context['assignee_name'] = $assignee->full_name;

        unset($context['assignee_selection']);

        // this line added to create task without asking for confirmation
        $task = $this->taskCreationService->create(
            context: $context,
            creator: $staff,
            chatId: $chatId,
        );

        /*
         * Link the original request message and the confirmation message
         * to this task, the same way AiOrchestrator::createTask() does for
         * the non-ambiguous path. Without these links, TaskCandidateFinder
         * can never surface this task for follow-up messages sent in this
         * chat, since it depends entirely on task_telegram_messages rows.
         */
        if (! empty($context['source_message_id'])) {
            $this->messageLinks->link(
                $task,
                $chatId,
                (int) $context['source_message_id'],
                $staff,
                'original',
            );
        }

        /*
         * Move conversation to confirmation.
         */
        // $this->conversationService->update(
        //     conversation: $conversation,
        //     state: ConversationState::AWAITING_TASK_CONFIRMATION,
        //     context: $context,
        // );
        /*
        * Clear conversation state.
        */
        $this->conversationService->update(
            conversation: $conversation,
            state: ConversationState::IDLE,
            context: [],
        );

        /*
         * Remove candidate buttons.
         */
        $this->telegram->editMessageReplyMarkup(
            chatId: $chatId,
            messageId: $messageId,
            replyMarkup: [
                'inline_keyboard' => [],
            ],
        );

        /*
         * Show normal task confirmation.
         */
        // $this->telegram->sendMessage(
        //     $chatId,
        //     $this->formatConfirmation($context),
        //     [
        //         'inline_keyboard' => [
        //             [
        //                 [
        //                     'text' => '✅ Tasdiqlash',
        //                     'callback_data' =>
        //                         'task:create:confirm',
        //                 ],
        //                 [
        //                     'text' => '❌ Bekor qilish',
        //                     'callback_data' =>
        //                         'task:create:cancel',
        //                 ],
        //             ],
        //         ],
        //     ],
        //     parseMode: 'HTML',
        // );

        $response = $this->telegram->sendMessage(
            $chatId,
            $this->formatTaskCreatedMessage($task),
            parseMode: 'HTML',
        );

        if (isset($response['result']['message_id'])) {
            $this->messageLinks->link(
                $task,
                $chatId,
                (int) $response['result']['message_id'],
                null,
                'task_created_notification',
            );
        }

        $this->telegram->answerCallbackQuery(
            $callbackQueryId,
            '✅ Xodim tanlandi.',
        );
    }

    // private function formatConfirmation(
    //     array $context,
    // ): string {
    //     $title = $context['title'] ?? 'Nomaʼlum';

    //     $description = $context['description'] ?? '—';

    //     $assignee = $context['assignee_name']
    //         ?? 'Xodim tanlanmagan';

    //     $priority = $context['priority'] ?? 'normal';

    //     $deadline = ! empty($context['deadline'])
    //         ? \Carbon\Carbon::parse(
    //             $context['deadline'],
    //         )
    //             ->timezone(config('app.timezone'))
    //             ->format('d.m.Y H:i')
    //         : 'Belgilanmagan';

    //     return
    //         "📋 <b>Vazifani tasdiqlash</b>\n\n"
    //         . "📌 <b>Nomi:</b> {$title}\n"
    //         . "📝 <b>Tavsif:</b> {$description}\n"
    //         . "👤 <b>Mas'ul:</b> {$assignee}\n"
    //         . "🔥 <b>Muhimlik:</b> {$priority}\n"
    //         . "⏰ <b>Muddat:</b> {$deadline}";
    // }

    private function formatTaskCreatedMessage(
        Task $task,
    ): string {
        $assignee = $task->assignee
            ? $task->assignee->full_name
            : 'Xodim tayinlanmagan';

        $deadline = $task->deadline
            ? TashkentDateTime::format($task->deadline)
            : 'Belgilanmagan';

        return
            "✅ <b>Vazifa yaratildi</b>\n\n"
            . "📌 <b>Vazifa:</b> {$task->title}\n"
            . "👤 <b>Mas'ul:</b> {$assignee}\n"
            . "🔥 <b>Muhimlik:</b> "
            . "{$this->priorityLabel($task->priority)}\n"
            . "⏰ <b>Muddat:</b> {$deadline}";
    }
    private function priorityLabel(
        mixed $priority,
    ): string {
        $value = $priority instanceof \BackedEnum
            ? $priority->value
            : (string) $priority;

        return match ($value) {
            'urgent' => '🔴 Juda yuqori',
            'high' => '🟠 Yuqori',
            'normal' => '🟡 Normal',
            'low' => '🟢 Past',
            default => e($value ?: 'normal'),
        };
    }

}
