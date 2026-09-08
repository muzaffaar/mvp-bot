<?php

namespace App\Jobs\Telegram;

use App\Telegram\Services\TelegramUpdateProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessTelegramMediaGroup implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $cacheKey,
    ) {
    }

    public function handle(
        TelegramUpdateProcessor $processor,
    ): void {
        $messages = Cache::pull($this->cacheKey, []);

        if (! is_array($messages) || $messages === []) {
            return;
        }

        // Media groups are a transport-level Telegram concept. They are
        // delivered as several updates but must become one completion comment.
        $firstUpdate = $messages[0];
        $telegramMessages = array_values(array_filter(
            array_map(
                static fn ($update) => $update['message'] ?? null,
                $messages
            ),
            'is_array'
        ));

        if ($telegramMessages === []) {
            return;
        }

        $processor->processMediaGroup(
            update: $firstUpdate,
            messages: $telegramMessages,
        );
    }
}
