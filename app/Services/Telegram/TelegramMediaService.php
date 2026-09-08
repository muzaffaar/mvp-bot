<?php

namespace App\Services\Telegram;

use App\AI\Gemini\GeminiClient;
use App\Enums\TaskSourceType;
use App\Telegram\Services\TelegramClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramMediaService
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly GeminiClient $gemini,
    ) {
    }

    /**
     * Extract, persist and transcribe all media carried by one Telegram message.
     *
     * The returned structure is intentionally JSON-serializable so it can be
     * stored in the task metadata JSON column.
     */
    public function process(array $message, int $taskId): array
    {
        $attachments = [];
        $transcriptParts = [];

        if (! empty($message['photo']) && is_array($message['photo'])) {
            $photo = collect($message['photo'])
                ->filter(fn ($item) => isset($item['file_id']))
                ->sortByDesc(fn ($item) =>
                    ((int) ($item['width'] ?? 0)) * ((int) ($item['height'] ?? 0))
                )
                ->first();

            if ($photo) {
                $attachments[] = $this->downloadAndStore(
                    fileId: (string) $photo['file_id'],
                    taskId: $taskId,
                    type: TaskSourceType::PHOTO,
                    extension: 'jpg',
                    telegramMessageId: $message['message_id'] ?? null,
                    metadata: [
                        'width' => $photo['width'] ?? null,
                        'height' => $photo['height'] ?? null,
                        'file_unique_id' => $photo['file_unique_id'] ?? null,
                    ],
                );
            }
        }

        if (isset($message['document']['file_id'])) {
            $document = $message['document'];

            $attachments[] = $this->downloadAndStore(
                fileId: (string) $document['file_id'],
                taskId: $taskId,
                type: TaskSourceType::FILE,
                extension: pathinfo((string) ($document['file_name'] ?? ''), PATHINFO_EXTENSION) ?: null,
                telegramMessageId: $message['message_id'] ?? null,
                metadata: [
                    'file_name' => $document['file_name'] ?? null,
                    'file_unique_id' => $document['file_unique_id'] ?? null,
                ],
            );
        }

        if (isset($message['voice']['file_id'])) {
            $voice = $message['voice'];

            $attachment = $this->downloadAndStore(
                fileId: (string) $voice['file_id'],
                taskId: $taskId,
                type: TaskSourceType::VOICE,
                extension: 'ogg',
                telegramMessageId: $message['message_id'] ?? null,
                metadata: [
                    'duration' => $voice['duration'] ?? null,
                    'file_unique_id' => $voice['file_unique_id'] ?? null,
                ],
            );

            $attachment['transcript'] = $this->transcribeStored(
                path: $attachment['path'],
                mimeType: 'audio/ogg',
            );
            $transcriptParts[] = $attachment['transcript'];
            $attachments[] = $attachment;
        }

        if (isset($message['audio']['file_id'])) {
            $audio = $message['audio'];

            $attachment = $this->downloadAndStore(
                fileId: (string) $audio['file_id'],
                taskId: $taskId,
                type: TaskSourceType::AUDIO,
                extension: pathinfo((string) ($audio['file_name'] ?? ''), PATHINFO_EXTENSION) ?: 'mp3',
                telegramMessageId: $message['message_id'] ?? null,
                metadata: [
                    'duration' => $audio['duration'] ?? null,
                    'file_unique_id' => $audio['file_unique_id'] ?? null,
                    'file_name' => $audio['file_name'] ?? null,
                ],
            );

            $attachment['transcript'] = $this->transcribeStored(
                path: $attachment['path'],
                mimeType: $audio['mime_type'] ?? 'audio/mpeg',
            );
            $transcriptParts[] = $attachment['transcript'];
            $attachments[] = $attachment;
        }

        if (isset($message['video']['file_id'])) {
            $video = $message['video'];

            $attachment = $this->downloadAndStore(
                fileId: (string) $video['file_id'],
                taskId: $taskId,
                type: TaskSourceType::VIDEO,
                extension: 'mp4',
                telegramMessageId: $message['message_id'] ?? null,
                metadata: [
                    'duration' => $video['duration'] ?? null,
                    'width' => $video['width'] ?? null,
                    'height' => $video['height'] ?? null,
                    'file_unique_id' => $video['file_unique_id'] ?? null,
                ],
            );

            $attachment['transcript'] = $this->transcribeStored(
                path: $attachment['path'],
                mimeType: $video['mime_type'] ?? 'video/mp4',
            );
            $transcriptParts[] = $attachment['transcript'];
            $attachments[] = $attachment;
        }

        if (isset($message['video_note']['file_id'])) {
            $videoNote = $message['video_note'];

            $attachment = $this->downloadAndStore(
                fileId: (string) $videoNote['file_id'],
                taskId: $taskId,
                type: TaskSourceType::VIDEO,
                extension: 'mp4',
                telegramMessageId: $message['message_id'] ?? null,
                metadata: [
                    'duration' => $videoNote['duration'] ?? null,
                    'width' => $videoNote['length'] ?? null,
                    'height' => $videoNote['length'] ?? null,
                    'file_unique_id' => $videoNote['file_unique_id'] ?? null,
                    'video_note' => true,
                ],
            );

            $attachment['transcript'] = $this->transcribeStored(
                path: $attachment['path'],
                mimeType: 'video/mp4',
            );
            $transcriptParts[] = $attachment['transcript'];
            $attachments[] = $attachment;
        }

        return [
            'attachments' => $attachments,
            'transcript' => $this->combineTranscripts($transcriptParts),
        ];
    }

    private function downloadAndStore(
        string $fileId,
        int $taskId,
        TaskSourceType $type,
        ?string $extension,
        int|string|null $telegramMessageId,
        array $metadata = [],
    ): array {
        $fileResponse = $this->telegram->getFile($fileId);
        $filePath = $fileResponse['result']['file_path'] ?? null;

        if (! is_string($filePath) || $filePath === '') {
            throw new RuntimeException('Telegram did not return the media file path.');
        }

        $contents = $this->telegram->downloadFile($filePath);
        $extension ??= pathinfo($filePath, PATHINFO_EXTENSION) ?: 'bin';
        $extension = strtolower($extension);

        $path = sprintf(
            'task-completions/%d/%s.%s',
            $taskId,
            Str::uuid()->toString(),
            $extension,
        );

        Storage::disk('public')->put($path, $contents);

        return array_merge([
            'type' => $type->value,
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => $this->mimeType($type, $extension),
            'size' => strlen($contents),
            'telegram_file_id' => $fileId,
            'telegram_message_id' => $telegramMessageId,
        ], $metadata);
    }

    private function transcribeStored(string $path, string $mimeType): string
    {
        $contents = Storage::disk('public')->get($path);

        return $this->gemini->transcribeAudio(
            audio: $contents,
            mimeType: $mimeType,
        );
    }

    private function combineTranscripts(array $transcripts): ?string
    {
        $transcripts = array_values(array_filter(
            array_map('trim', $transcripts),
            fn (string $text) => $text !== '',
        ));

        return $transcripts !== [] ? implode("\n\n", $transcripts) : null;
    }

    private function mimeType(TaskSourceType $type, string $extension): string
    {
        return match ($type) {
            TaskSourceType::PHOTO => 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension),
            TaskSourceType::VOICE => 'audio/ogg',
            TaskSourceType::VIDEO => 'video/mp4',
            default => 'application/octet-stream',
        };
    }
}
