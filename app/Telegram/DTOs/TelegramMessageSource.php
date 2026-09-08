<?php

namespace App\Telegram\DTOs;

use App\Enums\TaskSourceType;

final readonly class TelegramMessageSource
{
    public function __construct(
        public TaskSourceType $type,
        public int|string $messageId,
    ) {}

    public function channel(): string
    {
        return 'telegram';
    }
}
