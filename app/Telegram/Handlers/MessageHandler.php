<?php

namespace App\Telegram\Handlers;

use App\AI\Services\AiOrchestrator;
use App\AI\Services\ConversationService;
use App\Enums\TelegramConversationState;
use App\Telegram\Services\TelegramStaffResolver;
use App\AI\Gemini\GeminiClient;
use App\Telegram\Services\TelegramClient;
use App\Telegram\Callbacks\TaskManagementCallback;
use RuntimeException;
use Illuminate\Support\Facades\Storage;
use App\Services\Task\Context\TaskMessageContextResolver;

class MessageHandler
{
    public function __construct(
        private readonly AiOrchestrator $ai,
        private readonly TelegramStaffResolver $staffResolver,
        private readonly ConversationService $conversationService,
        private readonly TaskCompletionCommentHandler $completionCommentHandler,
        private readonly GeminiClient $gemini,
        private readonly TelegramClient $telegram,
        private readonly TaskManagementCallback $taskManagement,
        private readonly TaskMessageContextResolver $taskContextResolver,
    ) {
    }

    public function handle(array $message): void
    {
        $this->handleMessages([$message]);
    }

    /**
     * Handle one Telegram message, or the messages belonging to one
     * Telegram media group as a single logical user submission.
     */
    public function handleMessages(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $message = $messages[0];
        $staff = $this->staffResolver->resolve($message);

        $chatId = (int) ($message['chat']['id'] ?? 0);

        if ($chatId === 0) {
            return;
        }

        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        if ($conversation->state === TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT->value) {
            $payload = $this->prepareTaskManagementComment($messages);
            if ($payload['text'] === '' && $payload['attachments'] === []) {
                $this->telegram->sendMessage($chatId, '❌ Izoh, voice yoki video yuboring.');
                return;
            }
            $this->taskManagement->handleInput($staff, $chatId, $payload['text'], $payload['attachments']);
            return;
        }

        if ($conversation->state === TelegramConversationState::WAITING_TASK_MANAGEMENT_INPUT->value) {
            $text = $this->prepareTaskManagementInput($message);
            if ($text === '') {
                $this->telegram->sendMessage($chatId, '❌ Matn, voice yoki video yuboring.');
                return;
            }
            $this->taskManagement->handleInput($staff, $chatId, $text);
            return;
        }

        if ($conversation->state === TelegramConversationState::WAITING_TASK_MANAGEMENT_ASSIGNEE_SEARCH->value) {
            $text = trim($this->extractText($message));
            if ($text === '') {
                $this->telegram->sendMessage($chatId, '❌ Qidiruv uchun xodim ismini yozing.');
                return;
            }
            $this->taskManagement->handleInput($staff, $chatId, $text);
            return;
        }

        if (
            $conversation->state
            === TelegramConversationState::WAITING_TASK_COMPLETION_COMMENT->value
        ) {
            $this->completionCommentHandler->handle(
                staff: $staff,
                chatId: $chatId,
                messages: $messages,
            );

            return;
        }

        // Preserve the existing normal-message pipeline exactly.
        $text = $this->extractText($message);

        /*
        |--------------------------------------------------------------------------
        | Group conversation isolation
        |--------------------------------------------------------------------------
        |
        | Telegram groups may contain normal conversations between admins and staff.
        | Do not send ordinary conversation messages to the task AI pipeline.
        |
        | A reply in a group is allowed into task processing only when the replied
        | message is explicitly linked to a task.
        |
        */

        if ($this->isGroupChat($message)) {
            $reply = $message['reply_to_message'] ?? null;

            if ($reply !== null) {
                $messageContext = $this->taskContextResolver->resolve(
                    $staff,
                    $message
                );

                /*
                * This is a reply to an ordinary group message.
                *
                * It is not a task follow-up, so completely ignore it.
                */
                if (
                    ($messageContext['reply_task']['id'] ?? null) === null
                ) {
                    return;
                }
            }
        }

        if ($text === null) {
            return;
        }

        $this->ai->processText(
            staff: $staff,
            message: $message,
            text: $text,
        );
    }

    private function isGroupChat(array $message): bool
    {
        return in_array(
            $message['chat']['type'] ?? null,
            ['group', 'supergroup'],
            true
        );
    }

    /**
     * Task title/description editing is text-based in the database, so voice and
     * video inputs are transcribed before being passed to the existing edit flow.
     */
    private function prepareTaskManagementInput(array $message): string
    {
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        foreach (['voice' => 'audio/ogg', 'video' => 'video/mp4', 'video_note' => 'video/mp4'] as $field => $fallbackMime) {
            if (! isset($message[$field]['file_id'])) {
                continue;
            }

            $media = $message[$field];
            $file = $this->telegram->getFile($media['file_id']);
            $filePath = $file['result']['file_path'] ?? null;

            if (! is_string($filePath) || $filePath === '') {
                throw new RuntimeException('Telegram media file path topilmadi.');
            }

            $contents = $this->telegram->downloadFile($filePath);
            $mime = $media['mime_type'] ?? $fallbackMime;

            return trim((string) $this->gemini->transcribeAudio(
                audio: $contents,
                mimeType: $mime,
            ));
        }

        return '';
    }

    private function prepareTaskManagementComment(array $messages): array
    {
        $texts = [];
        $attachments = [];

        foreach ($messages as $message) {
            $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
            if ($text !== '') $texts[] = $text;

            foreach (['voice' => 'voice', 'video' => 'video', 'video_note' => 'video'] as $field => $type) {
                if (! isset($message[$field]['file_id'])) continue;
                $media = $message[$field];
                $file = $this->telegram->getFile($media['file_id']);
                $filePath = $file['result']['file_path'] ?? null;
                if (! is_string($filePath) || $filePath === '') throw new RuntimeException('Telegram media file path topilmadi.');
                $contents = $this->telegram->downloadFile($filePath);
                $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: ($type === 'voice' ? 'ogg' : 'mp4');
                $path = 'task-comments/' . date('Y/m') . '/' . $type . '-' . ($message['message_id'] ?? uniqid()) . '.' . $extension;
                Storage::disk('public')->put($path, $contents);
                $mime = $type === 'voice' ? 'audio/ogg' : ($media['mime_type'] ?? 'video/mp4');
                $transcription = $this->gemini->transcribeAudio(audio: $contents, mimeType: $mime);
                if (is_string($transcription) && trim($transcription) !== '') $texts[] = trim($transcription);
                $attachments[] = [
                    'type' => $type,
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'mime_type' => $mime,
                    'size' => strlen($contents),
                    'telegram_file_id' => $media['file_id'],
                    'telegram_file_unique_id' => $media['file_unique_id'] ?? null,
                    'message_id' => $message['message_id'] ?? null,
                    'duration' => $media['duration'] ?? null,
                    'transcription' => $transcription,
                ];
            }
        }

        return ['text' => implode("\n\n", $texts), 'attachments' => $attachments];
    }

    private function extractText(array $message): ?string
    {
        if (isset($message['text'])) {
            $text = trim((string) $message['text']);

            return $text !== '' ? $text : null;
        }

        if (! isset($message['voice']['file_id'])) {
            return null;
        }

        return $this->transcribeVoice(
            $message['voice']['file_id']
        );
    }

    private function transcribeVoice(string $fileId): string
    {
        $fileResponse = $this->telegram->getFile($fileId);

        $filePath = $fileResponse['result']['file_path'] ?? null;

        if (! is_string($filePath) || $filePath === '') {
            throw new RuntimeException(
                'Telegram did not return the voice file path.'
            );
        }

        $audio = $this->telegram->downloadFile($filePath);

        return $this->gemini->transcribeAudio(
            audio: $audio,
            mimeType: 'audio/ogg',
        );
    }
}
