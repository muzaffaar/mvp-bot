<?php

namespace App\Jobs\Telegram;

use App\Services\Telegram\TelegramMessageSourceResolver;
use App\Telegram\Services\TelegramUpdateProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly array $update
    ) {
    }

    public function handle(
        TelegramUpdateProcessor $processor,
        TelegramMessageSourceResolver $sourceResolver,
    ): void {
        /*
         * Callback queries do not contain a top-level "message".
         *
         * They must go directly to TelegramUpdateProcessor,
         * which already routes them to CallbackQueryHandler.
         */
        if (isset($this->update['callback_query'])) {
            $processor->process(
                update: $this->update,
                source: null,
            );

            return;
        }

        /*
         * Normal Telegram messages.
         */
        $message = $this->update['message'] ?? null;

        if (!$message) {
            return;
        }

        /*
         * Resolve source metadata only for the original incoming message.
         */
        $source = $sourceResolver->resolve($message);

        $processor->process(
            update: $this->update,
            source: $source,
        );
    }
}
