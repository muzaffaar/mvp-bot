<?php

namespace App\AI\Services;

use App\AI\Gemini\GeminiClient;
use App\AI\Prompts\TaskManagementPrompt;
use App\AI\Services\Clarification\TaskClarificationService;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TelegramConversation;
use App\Services\Task\TaskCreationService;
use App\Services\Task\TaskUpdateService;
use App\Services\Task\Context\TaskMessageContextResolver;
use App\Services\Task\Context\TaskMessageLinkService;
use App\Services\Task\TaskService;
use App\Services\Telegram\TelegramMessageSourceResolver;
use App\Telegram\DTOs\TelegramMessageSource;
use App\Telegram\Enums\ConversationState;
use App\Telegram\Services\TelegramClient;
use App\Telegram\Services\AssigneeResolver;
use Carbon\Carbon;
use App\Support\TashkentDateTime;
use Illuminate\Support\Facades\Log;

class AiOrchestrator
{
    public function __construct(
        private readonly AssigneeResolver $assigneeResolver,
        private readonly GeminiClient $gemini,
        private readonly ConversationService $conversationService,
        private readonly EntityResolver $entityResolver,
        private readonly TelegramClient $telegram,
        private readonly TaskService $taskService,
        private readonly TaskClarificationService $clarificationService,
        private readonly TelegramMessageSourceResolver $sourceResolver,
        private readonly TaskCreationService $taskCreationService,
        private readonly TaskMessageContextResolver $contextResolver,
        private readonly TaskUpdateService $taskUpdateService,
        private readonly TaskMessageLinkService $messageLinks,
    ) {
    }

    public function processText(
        Staff $staff,
        array $message,
        string $text,
    ): void {
        $chatId = (int) $message['chat']['id'];

        $source = $this->sourceResolver->resolve($message);

        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $state = $this->conversationService->state($conversation);

        /*
         * Existing task confirmation.
         */
        // if (
        //     $state === ConversationState::AWAITING_TASK_CONFIRMATION
        // ) {
        //     $this->telegram->sendMessage(
        //         $chatId,
        //         'Iltimos, vazifani yaratishni tasdiqlash yoki bekor qilish tugmasidan foydalaning.',
        //         parseMode: 'HTML',
        //     );

        //     return;
        // }

        /*
         * Existing assignee clarification.
         *
         * This state is only used when an assignee was explicitly
         * mentioned but requires clarification/resolution.
         *
         * A task with NO assignee does NOT enter this state.
         */
        // if (
        //     $state === ConversationState::AWAITING_ASSIGNEE
        // ) {
        //     $result = $this->clarificationService->handleAssignee(
        //         staff: $staff,
        //         chatId: $chatId,
        //         conversation: $conversation,
        //         text: $text,
        //     );

        //     if ($result === null) {
        //         return;
        //     }

        //     if (
        //         ($result['status'] ?? null)
        //         === 'assignee_selection_required'
        //     ) {
        //         $this->handleAssigneeSelectionRequired(
        //             chatId: $chatId,
        //             conversation: $conversation,
        //             result: $result,
        //         );

        //         return;
        //     }

        //     $this->confirmTask(
        //         conversation: $conversation,
        //         chatId: $chatId,
        //         context: $result,
        //     );

        //     return;
        // }

        if (
            $state === ConversationState::AWAITING_ASSIGNEE
        ) {
            $result = $this->clarificationService->handleAssignee(
                staff: $staff,
                chatId: $chatId,
                conversation: $conversation,
                text: $text,
            );

            /*
            * Assignee could not be resolved.
            *
            * Treat THIS message as a completely new task.
            */
            if (($result['status'] ?? null) === 'new_task') {
                /*
                * Clear the old clarification context/state.
                */
                $this->conversationService->update(
                    conversation: $conversation,
                    state: ConversationState::IDLE,
                    context: [],
                );

                /*
                * Continue below into the normal new-task flow.
                */
            } else {
                if (
                    ($result['status'] ?? null)
                    === 'assignee_selection_required'
                ) {
                    $this->handleAssigneeSelectionRequired(
                        chatId: $chatId,
                        conversation: $conversation,
                        result: $result,
                    );

                    return;
                }

                // $this->confirmTask(
                //     conversation: $conversation,
                //     chatId: $chatId,
                //     context: $result,
                // );

                return;
            }
        }

        /*
         * New AI request.
         */
        $conversationContext = $this->conversationService
            ->context($conversation);

        $messageContext = $this->contextResolver->resolve($staff, $message);

        $input = $this->buildInput(
            text: $text,
            context: $conversationContext,
            messageContext: $messageContext,
        );

        try {
            $result = $this->gemini->interpret(
                systemInstruction:
                    TaskManagementPrompt::messageDecision(),

                input:
                    $input,

                schema:
                    AiIntentSchema::messageDecision(),
            );

            $this->handleResult(
                staff: $staff,
                chatId: $chatId,
                conversation: $conversation,
                result: $result,
                source: $source,
                messageId: $message['message_id'] ?? null,
                originalUserMessage: $text,
                messageContext: $messageContext,
                message: $message,
            );
        } catch (\Throwable $e) {
            Log::error(
                'Telegram AI processing failed.',
                [
                    'staff_id' => $staff->id,
                    'chat_id' => $chatId,
                    'exception' => $e,
                ],
            );

            $this->telegram->sendMessage(
                $chatId,
                'Kechirasiz, so‘rovni qayta ishlashda xatolik yuz berdi. '
                . 'Iltimos, qayta urinib ko‘ring.',
                parseMode: 'HTML',
            );
        }
    }

    private function buildInput(
        string $text,
        array $context,
        array $messageContext,
    ): string {
        return json_encode(
            [
                'current_datetime' => now(TashkentDateTime::TIMEZONE)->toIso8601String(),

                'timezone' => TashkentDateTime::TIMEZONE,
                'timezone_instruction' => 'All user-provided dates and times are Uzbekistan/Tashkent local time (UTC+05:00). Preserve the requested clock time and return ISO-8601 with +05:00 offset, not UTC.',

                'conversation_context' =>
                    $context,

                'message_context' => $messageContext,

                'user_message' =>
                    $text,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT,
        );
    }

    private function handleResult(
        Staff $staff,
        int $chatId,
        TelegramConversation $conversation,
        array $result,
        ?int $messageId,
        TelegramMessageSource $source,
        string $originalUserMessage,
        array $messageContext,
        array $message,
    ): void {
        $action = $result['action'] ?? 'not_task';

        /*
         * A reply that is already linked to a task is deterministic context.
         * Gemini still decides WHICH fields changed, but it must never lose the
         * target task because of an ambiguous target_task_id/confidence value.
         */
        $replyTaskId = (int) data_get($messageContext, 'reply_task.id', 0);
        $hasLinkedReplyTask = $replyTaskId > 0;

        if ($hasLinkedReplyTask) {
            // A Telegram reply to any message already linked to a task is
            // authoritative routing context. AI decides the fields, not the
            // target task. This prevents reply follow-ups from being silently
            // treated as a new task or not_task.
            $action = 'update_task';
            $result['action'] = 'update_task';
            $result['target_task_id'] = $replyTaskId;
            $result['confidence'] = max((float) ($result['confidence'] ?? 0), 1.0);
        }

        if ($action === 'update_task') {
            $taskId = $hasLinkedReplyTask
                ? $replyTaskId
                : (int) ($result['target_task_id'] ?? 0);

            $task = $taskId > 0 ? Task::query()->find($taskId) : null;
            $threshold = $hasLinkedReplyTask
                ? 0.0
                : (($messageContext['is_reply'] ?? false) ? 0.70 : 0.85);

            if ($task && (float) ($result['confidence'] ?? 0) >= $threshold) {
                $update = $this->taskUpdateService->apply(
                    task: $task,
                    actor: $staff,
                    decision: $result,
                    chatId: $chatId,
                    messageId: (int) ($message['message_id'] ?? 0),
                );

                // Do not post task-change notifications back into the source
                // group/chat. TaskChanged notifies the affected staff directly
                // through the notification channel, preventing duplicate group
                // + private notifications for the same update.
            } elseif ($hasLinkedReplyTask) {
                Log::warning('Linked Telegram reply could not be applied as task update.', [
                    'task_id' => $replyTaskId,
                    'message_id' => $message['message_id'] ?? null,
                    'confidence' => $result['confidence'] ?? null,
                ]);
                $this->telegram->sendMessage(
                    $chatId,
                    'ℹ️ Bu xabar vazifaga bog‘landi, lekin aniq o‘zgarish aniqlanmadi.',
                );
            }

            return;
        }

        if ($action !== 'create_task') {
            // $this->telegram->sendMessage(
            //     $chatId,
            //     'So‘rovni tushunmadim. Vazifa yaratish, vazifa holatini o‘zgartirish yoki vazifani qabul qilish bo‘yicha so‘rov yuboring.',
            //     parseMode: 'HTML',
            // );

            return;
        }

        $result['intent'] = 'create_task';

        if (! empty($result['deadline'])) {
            $result['deadline'] = TashkentDateTime::normalize($result['deadline'])?->toIso8601String();
        }

        $context = array_merge(
            $conversation->context ?? [],
            [
                ...$result,

                'source_type' =>
                    $source->type->value,

                'source_message_id' =>
                    (string) $source->messageId,

                'original_user_message' =>
                    $originalUserMessage,
            ],
        );

        $this->continueTaskResolution(
            staff: $staff,
            chatId: $chatId,
            conversation: $conversation,
            context: $context,
        );
    }

    /**
     * Resolve optional task assignment.
     *
     * Rules:
     *
     * 1. Explicit assignee:
     *    Resolve the staff member normally.
     *
     * 2. No assignee:
     *    Create/confirm the task without an assignee.
     *
     * Missing assignee is NOT a clarification state.
     */
    private function continueTaskResolution(
        Staff $staff,
        int $chatId,
        TelegramConversation $conversation,
        array $context,
    ): void {
        $assignmentType = $context['assignment_type'] ?? 'group';

        $assigneeName = trim(
            (string) ($context['assignee_name'] ?? '')
        );

        $hasExplicitAssignee = $assigneeName !== '';

        /*
        * AI identified a specific assignee.
        */
        if ($assignmentType === 'direct' || $hasExplicitAssignee) {
            if ($assigneeName === '') {
                Log::warning(
                    'AI returned direct assignment without assignee name.',
                    [
                        'staff_id' => $staff->id,
                        'chat_id' => $chatId,
                        'context' => $context,
                    ],
                );

                $this->telegram->sendMessage(
                    $chatId,
                    'Aniq xodimni aniqlay olmadim. Iltimos, xodim ismini aniqroq yuboring.',
                    parseMode: 'HTML',
                );

                return;
            }

            $candidates = $this->assigneeResolver->findCandidates(
                name: $assigneeName,
                chatId: $chatId,
            );

            /*
            * Explicit assignee was mentioned but could not be found.
            */
            // if ($candidates->isEmpty()) {
            //     $this->conversationService->update(
            //         conversation: $conversation,
            //         state: ConversationState::AWAITING_ASSIGNEE,
            //         context: $context,
            //     );

            //     $this->telegram->sendMessage(
            //         $chatId,
            //         "“{$assigneeName}” xodimini topa olmadim. "
            //         . "Xodim ismini aniqroq yuboring.",
            //         parseMode: 'HTML',
            //     );

            //     return;
            // }

            if ($candidates->isEmpty()) {
                /*
                * Do NOT enter AWAITING_ASSIGNEE.
                *
                * The task will simply remain unresolved and the current
                * message has already been processed as a new task.
                */
                $context['assignment_type'] = 'group';
                $context['assignee_id'] = null;
                $context['assignee_name'] = null;

                // $this->confirmTask(
                //     conversation: $conversation,
                //     chatId: $chatId,
                //     context: $context,
                // );

                $this->createTask(
                    staff: $staff,
                    conversation: $conversation,
                    chatId: $chatId,
                    context: $context,
                );

                return;
            }

            /*
            * Multiple candidates require explicit selection.
            */
            if ($candidates->count() > 1) {
                $this->handleAssigneeSelectionRequired(
                    chatId: $chatId,
                    conversation: $conversation,
                    result: [
                        'status' => 'assignee_selection_required',

                        'assignee_name' => $assigneeName,

                        'candidates' => $candidates
                            ->map(
                                fn (Staff $candidate) => [
                                    'id' => $candidate->id,
                                    'name' => $candidate->full_name,
                                    'username' => $candidate->username,
                                ],
                            )
                            ->values()
                            ->all(),

                        'context' => $context,
                    ],
                );

                return;
            }

            /** @var Staff $assignee */
            $assignee = $candidates->first();

            $context['assignment_type'] = 'direct';
            $context['assignee_id'] = $assignee->id;
            $context['assignee_name'] = $assignee->full_name;

            // $this->confirmTask(
            //     conversation: $conversation,
            //     chatId: $chatId,
            //     context: $context,
            // );

            $this->createTask(
                staff: $staff,
                conversation: $conversation,
                chatId: $chatId,
                context: $context,
            );

            return;
        }

        /*
        * Explicitly open to the whole group.
        */
        if ($assignmentType === 'group') {
            $context['assignment_type'] = 'group';
            $context['assignee_id'] = null;
            $context['assignee_name'] = null;

            // $this->confirmTask(
            //     conversation: $conversation,
            //     chatId: $chatId,
            //     context: $context,
            // );

            $this->createTask(
                staff: $staff,
                conversation: $conversation,
                chatId: $chatId,
                context: $context,
            );

            return;
        }

        /*
        * No assignee was mentioned.
        *
        * IMPORTANT:
        * Do not convert this into group assignment.
        */
        $context['assignment_type'] = 'group';
        $context['assignee_id'] = null;
        $context['assignee_name'] = null;

        // $this->confirmTask(
        //     conversation: $conversation,
        //     chatId: $chatId,
        //     context: $context,
        // );

        $this->createTask(
            staff: $staff,
            conversation: $conversation,
            chatId: $chatId,
            context: $context,
        );
    }

    private function formatTaskUpdatedMessage(Task $task, array $changes): string
    {
        $lines = [
            '🔄 <b>Vazifa yangilandi</b>',
            '',
            "🔢 <b>Raqam:</b> {$task->task_number}",
            "📌 <b>Vazifa:</b> " . e($task->title),
            '',
        ];

        if ($changes === []) {
            $lines[] = 'ℹ️ <b>O‘zgarish:</b> Xabar vazifa kontekstiga bog‘landi, lekin saqlanadigan maydon o‘zgarmadi.';
            return implode("\n", $lines);
        }

        $lines[] = '<b>O‘zgarishlar:</b>';
        foreach ($changes as $change) {
            $label = $change['label'] ?? 'O‘zgarish';
            $new = $change['new'] ?? null;
            if (is_string($new)) {
                $new = e($new);
            }
            $lines[] = "• <b>{$label}:</b> " . ($new ?? '—');
        }

        return implode("\n", $lines);
    }

private function createTask(
    Staff $staff,
    TelegramConversation $conversation,
    int $chatId,
    array $context,
): void {
    $task = $this->taskCreationService->create(
        context: $context,
        creator: $staff,
        chatId: $chatId,
    );

    if (! empty($context['source_message_id'])) {
        $this->messageLinks->link($task, $chatId, (int) $context['source_message_id'], $staff, 'original');
    }

    $this->conversationService->update(
        conversation: $conversation,
        state: ConversationState::IDLE,
        context: [],
    );

    $response = $this->telegram->sendMessage(
        $chatId,
        $this->formatTaskCreatedMessage($task),
        parseMode: 'HTML',
    );

    if (isset($response['result']['message_id'])) {
        $this->messageLinks->link($task, $chatId, (int) $response['result']['message_id'], null, 'task_created_notification');
    }
}

    private function formatTaskCreatedMessage(
        Task $task,
    ): string {
        $assignee = $task->assignee
            ? $task->assignee->full_name
            : 'Xodim tayinlanmagan';

        $deadline = TashkentDateTime::format($task->deadline);

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

    private function handleAssigneeSelectionRequired(
        int $chatId,
        TelegramConversation $conversation,
        array $result,
    ): void {
        $candidates = $result['candidates'] ?? [];

        if (empty($candidates)) {
            return;
        }

        /*
         * Preserve the original task context while waiting for the
         * user's candidate selection.
         */
        $context = $result['context'] ?? [];

        $context['assignee_selection'] = [
            'assignee_name' =>
                $result['assignee_name'] ?? null,

            'candidate_ids' =>
                array_map(
                    static fn (array $candidate): int =>
                        (int) $candidate['id'],
                    $candidates,
                ),
        ];

        $this->conversationService->update(
            conversation: $conversation,
            state: ConversationState::AWAITING_ASSIGNEE,
            context: $context,
        );

        $keyboard = [];

        foreach ($candidates as $candidate) {
            $name = $candidate['name'];

            if (! empty($candidate['username'])) {
                $name .= ' @' . ltrim(
                    $candidate['username'],
                    '@',
                );
            }

            $keyboard[] = [
                [
                    'text' => $name,
                    'callback_data' =>
                        'task:assignee:' . $candidate['id'],
                ],
            ];
        }

        $this->telegram->sendMessage(
            $chatId,
            '👤 <b>Bir nechta xodim topildi.</b>' . "\n\n"
            . 'Qaysi xodimga vazifani beray?',
            [
                'inline_keyboard' => $keyboard,
            ],
            parseMode: 'HTML',
        );
    }
}
