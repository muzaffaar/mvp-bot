<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramService
{
    private string $botToken;

    private string $botUsername;

    public function __construct()
    {
        $this->botToken = (string) config(
            'services.telegram.bot_token'
        );

        $this->botUsername = (string) config(
            'services.telegram.bot_username'
        );

        if ($this->botToken === '') {
            throw new RuntimeException(
                'TELEGRAM_BOT_TOKEN is not configured.'
            );
        }
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return Http::timeout(10)
            ->post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                $payload
            )
            ->throw()
            ->json();
    }

    public function loginUrl(string $token): string
    {
        return sprintf(
            'https://t.me/%s?start=%s',
            $this->botUsername,
            urlencode($token)
        );
    }

    public function answerCallbackQuery(
        string $callbackId,
        string $text
    ): void {
        $token = config(
            'services.telegram.bot_token'
        );

        Http::post(
            "https://api.telegram.org/bot{$token}/answerCallbackQuery",
            [
                'callback_query_id' => $callbackId,
                'text' => $text,
            ]
        );
    }
}
