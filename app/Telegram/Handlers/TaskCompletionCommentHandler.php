<?php

namespace App\Telegram\Handlers;

use App\AI\Gemini\GeminiClient;
use App\AI\Services\ConversationService;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\TaskStatusTransitionService;
use App\Telegram\Services\TelegramClient;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TaskCompletionCommentHandler
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly TaskStatusTransitionService $statusTransition,
        private readonly TelegramClient $telegram,
        private readonly GeminiClient $gemini,
    ) {
    }

    /**
     * A Telegram media group is one logical completion submission.
     * A normal message is simply a one-item array.
     */
    public function handle(
        Staff $staff,
        int $chatId,
        array $messages,
    ): void {
        $conversation = $this->conversationService->getOrCreate(
            staff: $staff,
            chatId: $chatId,
        );

        $context = $this->conversationService->context($conversation);
        $taskId = $context['task_id'] ?? null;

        if (! $taskId) {
            $this->conversationService->reset($conversation);
            $this->telegram->sendMessage($chatId, '❌ Vazifa maʼlumotlari topilmadi. Iltimos, qaytadan urinib ko‘ring.');
            return;
        }

        $task = Task::query()->with(['assignee', 'assignor'])->find($taskId);

        if (! $task) {
            $this->conversationService->reset($conversation);
            $this->telegram->sendMessage($chatId, '❌ Vazifa topilmadi.');
            return;
        }

        if ($task->assignee_id !== $staff->id) {
            $this->conversationService->reset($conversation);
            $this->telegram->sendMessage($chatId, '❌ Bu vazifa sizga biriktirilmagan.');
            return;
        }

        try {
            $result = $this->prepareSubmission($messages, $task);
            $comment = $result['text'];
            $attachments = $result['attachments'];

            if ($comment === '' && $attachments === []) {
                $this->telegram->sendMessage($chatId, '📝 Iltimos, vazifa bo‘yicha izoh yoki media yuboring.');
                return;
            }

            if ($comment !== '' && mb_strlen($comment) > 4000) {
                $this->telegram->sendMessage($chatId, '📝 Izoh juda uzun. Iltimos, 4000 belgidan oshirmang.');
                return;
            }

            if ($comment !== '' && mb_strlen($comment) < 3 && $attachments === []) {
                $this->telegram->sendMessage($chatId, '📝 Izoh juda qisqa. Kamida 3 ta belgi kiriting.');
                return;
            }

            $displayComment = $comment !== '' ? $comment : 'Media yuborildi.';

            $task = $this->statusTransition->change(
                task: $task,
                newStatus: TaskStatus::AWAITING_ACCEPTANCE,
                actor: $staff,
                message: $displayComment,
                metadata: [
                    'completion_attachments' => $attachments,
                    'completion_media_count' => count($attachments),
                ],
            );

            $managementCardMessageId = $context['management_card_message_id'] ?? null;
            $managementCardChatId = $context['management_card_chat_id'] ?? $chatId;

            $this->conversationService->reset($conversation);

            // When completion was started from /manage, preserve the card itself and
            // simply remove its now-stale action buttons. This prevents the chat from
            // filling up with new management cards after every action.
            if ($managementCardMessageId) {
                try {
                    $this->telegram->editMessageReplyMarkup(
                        chatId: (int) $managementCardChatId,
                        messageId: (int) $managementCardMessageId,
                        replyMarkup: null,
                    );
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $this->telegram->sendMessage(
                $chatId,
                "✅ <b>Vazifa bajarilgan deb belgilandi</b>\n\n"
                . "🔢 <b>Raqam:</b> {$task->task_number}\n"
                . "📌 <b>Vazifa:</b> " . e($task->title) . "\n"
                . "📊 <b>Status:</b> {$task->status->label()}\n\n"
                . "📝 <b>Izoh:</b>\n" . e($displayComment)
                . ($attachments !== [] ? "\n\n📎 <b>Media:</b> " . count($attachments) : ''),
                parseMode: 'HTML',
            );
        } catch (AuthorizationException) {
            $this->conversationService->reset($conversation);
            $this->telegram->sendMessage($chatId, '❌ Bu vazifani yakunlash huquqingiz yo‘q.');
        } catch (DomainException) {
            $this->conversationService->reset($conversation);
            $this->telegram->sendMessage($chatId, '❌ Vazifa hozir bajarilgan holatga o‘tkazilishi mumkin emas.');
        } catch (Throwable $e) {
            report($e);
            $this->telegram->sendMessage($chatId, '❌ Vazifani yakunlashda xatolik yuz berdi.');
        }
    }

    private function prepareSubmission(array $messages, Task $task): array
    {
        $texts = [];
        $attachments = [];

        foreach ($messages as $message) {
            $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
            if ($text !== '') {
                $texts[] = $text;
            }

            if (isset($message['photo']) && is_array($message['photo'])) {
                $photo = collect($message['photo'])->sortByDesc(fn ($item) => (int) ($item['file_size'] ?? 0))->first();
                if (is_array($photo) && isset($photo['file_id'])) {
                    $attachments[] = $this->downloadAttachment($photo['file_id'], 'photo', $task->id, $message['message_id'] ?? null);
                }
            }

            foreach ([
                'voice' => 'voice',
                'audio' => 'audio',
                'document' => 'document',
                'video' => 'video',
                'video_note' => 'video',
            ] as $field => $type) {
                if (! isset($message[$field]['file_id'])) {
                    continue;
                }

                $attachments[] = $this->downloadAttachment(
                    $message[$field]['file_id'],
                    $type,
                    $task->id,
                    $message['message_id'] ?? null,
                    $message[$field],
                );
            }
        }

        $transcripts = collect($attachments)
            ->pluck('transcription')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        if ($transcripts !== []) {
            $texts = array_merge($texts, $transcripts);
        }

        return [
            'text' => implode("\n\n", $texts),
            'attachments' => $attachments,
        ];
    }

    private function downloadAttachment(
        string $fileId,
        string $type,
        int $taskId,
        ?int $messageId,
        array $telegramMedia = [],
    ): array {
        $fileResponse = $this->telegram->getFile($fileId);
        $filePath = $fileResponse['result']['file_path'] ?? null;

        if (! is_string($filePath) || $filePath === '') {
            throw new RuntimeException('Telegram did not return the media file path.');
        }

        $contents = $this->telegram->downloadFile($filePath);
        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: match ($type) {
            'photo' => 'jpg',
            'voice' => 'ogg',
            'audio' => 'mp3',
            'video' => 'mp4',
            default => 'bin',
        };

        $path = sprintf(
            'task-completions/%d/%s-%s.%s',
            $taskId,
            $type,
            $messageId ?? uniqid(),
            $extension,
        );

        Storage::disk('public')->put($path, $contents);

        $transcription = null;
        if (in_array($type, ['voice', 'audio', 'video'], true)) {
            $mime = match ($type) {
                'voice' => 'audio/ogg',
                'audio' => $telegramMedia['mime_type'] ?? 'audio/mpeg',
                'video' => $telegramMedia['mime_type'] ?? 'video/mp4',
            };

            $transcription = $this->gemini->transcribeAudio(
                audio: $contents,
                mimeType: $mime,
            );
        }

        return [
            'type' => $type,
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => $telegramMedia['mime_type'] ?? $this->guessMime($extension),
            'size' => strlen($contents),
            'telegram_file_id' => $fileId,
            'telegram_file_unique_id' => $telegramMedia['file_unique_id'] ?? null,
            'message_id' => $messageId,
            'duration' => $telegramMedia['duration'] ?? null,
            'transcription' => $transcription,
        ];
    }

    private function guessMime(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'ogg' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
