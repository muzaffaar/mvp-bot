<?php

namespace App\Services\Telegram;

use App\Enums\TaskSourceType;
use App\Telegram\DTOs\TelegramMessageSource;

final class TelegramMessageSourceResolver
{
    public function resolve(array $message): TelegramMessageSource
    {
        return new TelegramMessageSource(
            type: $this->detectType($message),
            messageId: $message['message_id'],
        );
    }

    private function detectType(array $message): TaskSourceType
    {
        return match (true) {
            isset($message['text'])
                => TaskSourceType::TEXT,

            isset($message['voice'])
                => TaskSourceType::VOICE,

            isset($message['document'])
                => TaskSourceType::FILE,

            isset($message['photo'])
                => TaskSourceType::PHOTO,

            isset($message['video'])
                => TaskSourceType::VIDEO,

            isset($message['audio'])
                => TaskSourceType::AUDIO,

            default
                => TaskSourceType::OTHER,
        };
    }
}
