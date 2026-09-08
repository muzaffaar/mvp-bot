<?php

namespace App\Telegram\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramClient
{
    private string $baseUrl;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            throw new RuntimeException(
                'TELEGRAM_BOT_TOKEN is not configured.'
            );
        }

        $this->baseUrl =
            "https://api.telegram.org/bot{$token}";
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
        ?int $replyToMessageId = null,
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if ($replyToMessageId !== null) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        return $this->request()
            ->post('/sendMessage', $payload)
            ->throw()
            ->json();
    }

    public function answerCallbackQuery(
        string $callbackQueryId,
        ?string $text = null,
    ): array {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        return $this->request()
            ->post('/answerCallbackQuery', $payload)
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(20)
            ->retry(3, 200);
    }

    public function getFile(string $fileId): array
    {
        return $this->request()
            ->post('/getFile', [
                'file_id' => $fileId,
            ])
            ->throw()
            ->json();
    }

    public function downloadFile(string $filePath): string
    {
        $token = config('services.telegram.bot_token');

        return Http::timeout(30)
            ->retry(3, 500)
            ->get(
                "https://api.telegram.org/file/bot{$token}/{$filePath}"
            )
            ->throw()
            ->body();
    }

    public function sendPhoto(
        int|string $chatId,
        string $contents,
        string $filename = 'photo.jpg',
        ?string $caption = null,
        ?int $replyToMessageId = null,
        ?array $replyMarkup = null,
    ): array {
        $request = $this->request()
            ->attach('photo', $contents, $filename);

        $payload = ['chat_id' => $chatId];

        if ($replyToMessageId !== null) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        return $request->post('/sendPhoto', $payload)->throw()->json();
    }

    public function sendVideo(
        int|string $chatId,
        string $contents,
        string $filename = 'video.mp4',
        ?string $caption = null,
        ?int $replyToMessageId = null,
        ?array $replyMarkup = null,
    ): array {
        $request = $this->request()
            ->attach('video', $contents, $filename);

        $payload = ['chat_id' => $chatId];

        if ($replyToMessageId !== null) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        return $request->post('/sendVideo', $payload)->throw()->json();
    }


    /**
     * @param array<int, array{type: string, contents: string, filename: string}> $media
     */
    public function sendMediaGroup(
        int|string $chatId,
        array $media,
        ?string $caption = null,
    ): array {
        $request = $this->request();
        $payloadMedia = [];

        foreach ($media as $index => $item) {
            $field = "media_{$index}";
            $type = $item['type'] === 'video' ? 'video' : 'photo';

            $request = $request->attach(
                $field,
                $item['contents'],
                $item['filename'],
            );

            $entry = [
                'type' => $type,
                'media' => "attach://{$field}",
            ];

            if ($index === 0 && $caption !== null && $caption !== '') {
                $entry['caption'] = $caption;
                $entry['parse_mode'] = 'HTML';
            }

            $payloadMedia[] = $entry;
        }

        return $request->post('/sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($payloadMedia, JSON_THROW_ON_ERROR),
        ])->throw()->json();
    }

    public function sendDocument(
        int|string $chatId,
        string $contents,
        string $filename = 'document',
        ?string $caption = null,
        ?int $replyToMessageId = null,
    ): array {
        $request = $this->request()
            ->attach('document', $contents, $filename);

        $payload = ['chat_id' => $chatId];

        if ($replyToMessageId !== null) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }

        return $request->post('/sendDocument', $payload)->throw()->json();
    }

    public function sendMessageWithKeyboard(int|string $chatId, string $text, array $replyMarkup, ?string $parseMode = 'HTML'): array
    {
        return $this->sendMessage($chatId, $text, $replyMarkup, $parseMode);
    }

    public function deleteMessage(
        int|string $chatId,
        int $messageId,
    ): array {
        return $this->request()
            ->post('/deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ])
            ->throw()
            ->json();
    }

    public function editMessageReplyMarkup(
        int|string $chatId,
        int $messageId,
        ?array $replyMarkup = null,
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        return $this->request()
            ->post('/editMessageReplyMarkup', $payload)
            ->throw()
            ->json();
}
}
