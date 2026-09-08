<?php

namespace App\Notifications\Channels;

use App\Models\Staff;
use App\Telegram\Services\TelegramClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramChannel
{
    public function __construct(
        private readonly TelegramClient $telegram,
    ) {
    }

    public function send(Staff $notifiable, Notification $notification): void
    {
        $chatId = $notifiable->telegram_chat_id;

        if (! $chatId) {
            Log::warning('Telegram notification skipped: no chat ID', ['staff_id' => $notifiable->id]);
            return;
        }

        if (! method_exists($notification, 'toTelegram')) {
            Log::warning('Telegram notification skipped: toTelegram() not implemented', [
                'notification' => get_class($notification),
                'staff_id' => $notifiable->id,
            ]);
            return;
        }

        $message = $notification->toTelegram($notifiable);
        $replyMarkup = method_exists($notification, 'toTelegramMarkup')
            ? $notification->toTelegramMarkup($notifiable)
            : null;

        if (! $message) {
            return;
        }

        $attachments = method_exists($notification, 'toTelegramAttachments')
            ? $notification->toTelegramAttachments($notifiable)
            : [];

        $preparedAttachments = $this->prepareAttachments($attachments);
        $sentAttachmentMessageIds = [];

        try {
            // 1. No media: normal notification.
            if ($preparedAttachments === []) {
                $this->telegram->sendMessage(
                    chatId: $chatId,
                    text: $message,
                    parseMode: 'HTML',
                    replyMarkup: $replyMarkup,
                );

                return;
            }

            // 2. Exactly one photo/video: the notification itself is the media
            // message, with caption and inline buttons attached to it.
            if (count($preparedAttachments) === 1
                && in_array($preparedAttachments[0]['type'], ['photo', 'video'], true)
            ) {
                $attachment = $preparedAttachments[0];

                $response = $attachment['type'] === 'photo'
                    ? $this->telegram->sendPhoto(
                        $chatId,
                        $attachment['contents'],
                        $attachment['filename'],
                        $message,
                        null,
                        $replyMarkup,
                    )
                    : $this->telegram->sendVideo(
                        $chatId,
                        $attachment['contents'],
                        $attachment['filename'],
                        $message,
                        null,
                        $replyMarkup,
                    );

                $this->collectMessageId($response, $sentAttachmentMessageIds);
            }
            // 3. Multiple photos/videos: Telegram media groups cannot contain
            // inline keyboards. Put the notification text on the first item,
            // then send the existing action buttons as a reply to that group.
            elseif ($this->isMediaGroup($preparedAttachments)) {
                $response = $this->telegram->sendMediaGroup(
                    $chatId,
                    $preparedAttachments,
                    $message,
                );

                $firstMediaMessageId = null;
                foreach ((array) data_get($response, 'result', []) as $mediaMessage) {
                    $messageId = data_get($mediaMessage, 'message_id');
                    if (is_int($messageId) || (is_string($messageId) && ctype_digit($messageId))) {
                        $messageId = (int) $messageId;
                        $sentAttachmentMessageIds[] = $messageId;
                        $firstMediaMessageId ??= $messageId;
                    }
                }

                if ($replyMarkup !== null && $firstMediaMessageId !== null) {
                    $this->telegram->sendMessage(
                        chatId: $chatId,
                        text: '⬇️ <b>Vazifa boshqaruvi</b>',
                        parseMode: 'HTML',
                        replyMarkup: $replyMarkup,
                        replyToMessageId: $firstMediaMessageId,
                    );
                }
            }
            // 4. Mixed/other files: send normal notification first, then each
            // attachment as a reply to it.
            else {
                $notificationResponse = $this->telegram->sendMessage(
                    chatId: $chatId,
                    text: $message,
                    parseMode: 'HTML',
                    replyMarkup: $replyMarkup,
                );

                $notificationMessageId = $this->messageIdFromResponse($notificationResponse);

                foreach ($preparedAttachments as $attachment) {
                    $response = match ($attachment['type']) {
                        'photo' => $this->telegram->sendPhoto($chatId, $attachment['contents'], $attachment['filename'], null, $notificationMessageId),
                        'video' => $this->telegram->sendVideo($chatId, $attachment['contents'], $attachment['filename'], null, $notificationMessageId),
                        'document', 'voice', 'audio' => $this->telegram->sendDocument($chatId, $attachment['contents'], $attachment['filename'], null, $notificationMessageId),
                        default => null,
                    };

                    if ($response !== null) {
                        $this->collectMessageId($response, $sentAttachmentMessageIds);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Telegram notification send failed', [
                'staff_id' => $notifiable->id,
                'notification' => get_class($notification),
                'exception' => $e,
            ]);
        }

        if ($sentAttachmentMessageIds !== []
            && method_exists($notification, 'recordTelegramAttachmentMessageIds')
        ) {
            $notification->recordTelegramAttachmentMessageIds(
                $notifiable,
                $sentAttachmentMessageIds,
            );
        }
    }

    private function prepareAttachments(array $attachments): array
    {
        $prepared = [];

        foreach ($attachments as $attachment) {
            $storedPath = $attachment['path'] ?? null;
            if (! is_string($storedPath) || $storedPath === '' || ! Storage::disk('public')->exists($storedPath)) {
                continue;
            }

            $prepared[] = [
                'type' => $attachment['type'] ?? 'document',
                'contents' => Storage::disk('public')->get($storedPath),
                'filename' => basename($storedPath),
            ];
        }

        return $prepared;
    }

    private function isMediaGroup(array $attachments): bool
    {
        return count($attachments) > 1
            && collect($attachments)->every(
                fn (array $attachment) => in_array($attachment['type'], ['photo', 'video'], true)
            );
    }

    private function collectMessageId(array $response, array &$messageIds): void
    {
        $messageId = $this->messageIdFromResponse($response);
        if ($messageId !== null) {
            $messageIds[] = $messageId;
        }
    }

    private function messageIdFromResponse(array $response): ?int
    {
        $messageId = data_get($response, 'result.message_id');

        return (is_int($messageId) || (is_string($messageId) && ctype_digit($messageId)))
            ? (int) $messageId
            : null;
    }
}
