<?php

namespace App\Notifications\Channels;

use App\Models\Staff;
use App\Models\TaskTelegramMessage;
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

        $taskId = $this->taskIdForTelegramLink($notification);
        $telegramMessageRole = $this->telegramMessageRole($notification);

        /*
        |--------------------------------------------------------------------------
        | Replace previous task update notification
        |--------------------------------------------------------------------------
        |
        | For notifications that belong to a specific task and define a Telegram
        | message role, remove the previous notification for this exact task and
        | recipient before sending the new one.
        |
        */

        if ($taskId !== null && $telegramMessageRole !== null) {
            $this->deletePreviousTaskNotifications(
                taskId: $taskId,
                staffId: $notifiable->id,
                role: $telegramMessageRole,
            );
        }

        try {
            // 1. No media: normal notification.
            if ($preparedAttachments === []) {
                $response = $this->telegram->sendMessage(
                    chatId: $chatId,
                    text: $message,
                    parseMode: 'HTML',
                    replyMarkup: $replyMarkup,
                );

                $this->recordTaskNotificationMessage(
                    taskId: $taskId,
                    staffId: $notifiable->id,
                    chatId: (int) $chatId,
                    messageId: $this->messageIdFromResponse($response),
                    role: $telegramMessageRole,
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

    private function taskIdForTelegramLink(
        Notification $notification,
    ): ?int {
        if (! method_exists($notification, 'taskIdForTelegramLink')) {
            return null;
        }

        $taskId = $notification->taskIdForTelegramLink();

        return is_numeric($taskId)
            ? (int) $taskId
            : null;
    }

    private function telegramMessageRole(
        Notification $notification,
    ): ?string {
        if (! method_exists($notification, 'telegramMessageRole')) {
            return null;
        }

        $role = $notification->telegramMessageRole();

        return is_string($role) && $role !== ''
            ? $role
            : null;
    }

    private function deletePreviousTaskNotifications(
        int $taskId,
        int $staffId,
        string $role,
    ): void {
        $messages = TaskTelegramMessage::query()
            ->where('task_id', $taskId)
            ->where('staff_id', $staffId)
            ->where('role', $role)
            ->get();

        foreach ($messages as $message) {
            try {
                $this->telegram->deleteMessage(
                    (int) $message->chat_id,
                    (int) $message->message_id,
                );
            } catch (\Throwable $e) {
                /*
                * Telegram may already have deleted the message or the message
                * may no longer be deletable. We still remove the stale local
                * record below.
                */
                Log::debug(
                    'Previous Telegram task notification cleanup failed.',
                    [
                        'task_id' => $taskId,
                        'staff_id' => $staffId,
                        'message_id' => $message->message_id,
                    ]
                );
            }
        }

        TaskTelegramMessage::query()
            ->where('task_id', $taskId)
            ->where('staff_id', $staffId)
            ->where('role', $role)
            ->delete();
    }

    private function recordTaskNotificationMessage(
        ?int $taskId,
        int $staffId,
        int $chatId,
        ?int $messageId,
        ?string $role,
    ): void {
        if (
            $taskId === null
            || $messageId === null
            || $role === null
        ) {
            return;
        }

        TaskTelegramMessage::create([
            'task_id' => $taskId,
            'staff_id' => $staffId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'role' => $role,
        ]);
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
