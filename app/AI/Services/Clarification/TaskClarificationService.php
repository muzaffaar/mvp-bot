<?php

namespace App\AI\Services\Clarification;

use App\AI\Gemini\GeminiClient;
use App\AI\Prompts\TaskManagementPrompt;
use App\AI\Services\AiIntentSchema;
use App\AI\Services\ConversationService;
use App\Telegram\Services\AssigneeResolver;
use App\Models\Staff;
use App\Models\TelegramConversation;
use App\Telegram\Services\TelegramClient;

class TaskClarificationService
{
    public function __construct(
        private readonly AssigneeResolver $assigneeResolver,
        private readonly GeminiClient $gemini,
        private readonly ConversationService $conversationService,
        private readonly TelegramClient $telegram,
    ) {
    }

    /**
     * Handle assignee clarification.
     *
     * Group resolution is intentionally not part of this service.
     *
     * The current Telegram chat is already known by the orchestrator.
     * Staff membership should therefore be resolved using Telegram
     * context / staff.group_chat_id rather than a Group model.
     */
    public function handleAssignee(
        Staff $staff,
        int $chatId,
        TelegramConversation $conversation,
        string $text,
    ): ?array {
        $context = $this->conversationService
            ->context($conversation);

        /*
         * User explicitly wants the task open to everyone.
         */
        if ($this->isOpenAssignment($text)) {
            $context['assignment_type'] = 'group';
            $context['assignee_id'] = null;
            $context['assignee_name'] = null;

            return $context;
        }

        /*
         * The clarification answer may be a natural sentence rather than
         * a bare staff name, especially for voice messages. Extract the
         * intended assignee first, then let EntityResolver do database
         * matching.
         */
        $result = $this->gemini->interpret(
            systemInstruction: TaskManagementPrompt::assigneeClarification(),
            input: json_encode(
                [
                    'current_datetime' => now()->toIso8601String(),
                    'conversation_context' => $context,
                    'user_message' => $text,
                ],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ),
            schema: AiIntentSchema::assigneeClarification(),
        );

        if (($result['open_assignment'] ?? false) === true) {
            $context['assignment_type'] = 'group';
            $context['assignee_id'] = null;
            $context['assignee_name'] = null;

            return $context;
        }

        $assigneeName = trim((string) ($result['assignee_name'] ?? ''));

        // i commented out because bot does not need to ask for assignee name if mentioned assignee is not found
        // if ($assigneeName === '') {
        //     $this->telegram->sendMessage(
        //         $chatId,
        //         'Xodim nomini aniqlay olmadim. Iltimos, xodim ismini ayting.',
        //         parseMode: 'HTML',
        //     );

        //     return null;
        // }

        if ($assigneeName === '') {
            return [
                'status' => 'new_task',
                'text' => $text,
            ];
        }

        $candidates = $this->assigneeResolver->findCandidates(
            name: $assigneeName,
            chatId: $chatId,
        );

        // if assignee not found then it is group task
        // if ($candidates->isEmpty()) {
        //     $this->telegram->sendMessage(
        //         $chatId,
        //         "“{$assigneeName}” xodimini topa olmadim.\n\n"
        //         . "Xodim ismini qayta yuboring yoki "
        //         . "“hammaga” deb yozing.",
        //         parseMode: 'HTML',
        //     );

        //     return null;
        // }

        if ($candidates->isEmpty()) {
            return [
                'status' => 'new_task',
                'text' => $text,
            ];
        }

        if ($candidates->count() === 1) {
            $assignee = $candidates->first();

            $context['assignment_type'] = 'direct';
            $context['assignee_id'] = $assignee->id;
            $context['assignee_name'] = $assignee->full_name;

            return $context;
        }

        /*
        * More than one staff member matched.
        *
        * DO NOT choose the first one.
        * DO NOT create the task yet.
        *
        * The orchestrator must ask the user to choose one.
        */
        return [
            'status' => 'assignee_selection_required',
            'assignee_name' => $assigneeName,
            'candidates' => $candidates->map(
                fn (Staff $staff) => [
                    'id' => $staff->id,
                    'name' => $staff->full_name,
                    'username' => $staff->username,
                ]
            )->values()->all(),
            'context' => $context,
        ];
    }

    private function isOpenAssignment(string $text): bool
    {
        $normalized = mb_strtolower(
            trim($text)
        );

        return in_array(
            $normalized,
            [
                'hamma',
                'hammaga',
                'barchaga',
                'barcha',
                'hammasiga',
                'guruhga',
                'guruhdagilarga',
                'all',
                'everyone',
            ],
            true
        );
    }
}
