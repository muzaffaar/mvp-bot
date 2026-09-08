<?php

namespace App\Telegram\Services;

use App\Telegram\DTOs\TelegramMessageSource;
use App\Telegram\Handlers\CallbackQueryHandler;
use App\Telegram\Handlers\CommandHandler;
use App\Telegram\Handlers\MessageHandler;
use App\Telegram\Handlers\StartCommandHandler;
use Illuminate\Support\Facades\Log;

class TelegramUpdateProcessor
{
    public function __construct(
        private readonly CommandHandler $commandHandler,
        private readonly MessageHandler $messageHandler,
        private readonly CallbackQueryHandler $callbackQueryHandler,
        private readonly StartCommandHandler $startCommandHandler,
        private readonly TelegramMemberSynchronizer $memberSynchronizer,
        private readonly TelegramClient $telegram,
        private readonly TelegramStaffResolver $staffResolver,
    ) {
    }

    public function processMediaGroup(array $update, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $message = $messages[0];

        if ($this->hasNewChatMembers($message)) {
            $this->memberSynchronizer->sync($message);

            return;
        }

        if ($this->isStartCommand($message) || $this->isCommand($message)) {
            $this->process(
                update: ['message' => $message],
                source: null,
            );

            return;
        }

        /*
         * Only restrict messages coming from group chats.
         *
         * Private chats are allowed normally.
         */
        if ($this->isGroupChat($message)) {
            try {
                $staff = $this->staffResolver->resolve($message);

                if (! $staff->hasRole('super-admin')) {
                    Log::info(
                        'Telegram media group ignored: user is not super-admin.',
                        [
                            'telegram_user_id' => $message['from']['id'] ?? null,
                            'message_id' => $message['message_id'] ?? null,
                            'chat_id' => $message['chat']['id'] ?? null,
                        ]
                    );

                    return;
                }
            } catch (\Throwable $e) {
                Log::warning(
                    'Telegram group media ignored: staff could not be resolved.',
                    [
                        'telegram_user_id' => $message['from']['id'] ?? null,
                        'message_id' => $message['message_id'] ?? null,
                        'chat_id' => $message['chat']['id'] ?? null,
                    ]
                );

                return;
            }
        }

        $this->messageHandler->handleMessages($messages);
    }

    public function process(array $update, ?TelegramMessageSource $source): void
    {
        /*
         * Callback queries
         */
        if (isset($update['callback_query'])) {
            $this->callbackQueryHandler->handle(
                $update['callback_query']
            );

            return;
        }

        /*
         * Messages
         */
        if (! isset($update['message'])) {
            return;
        }

        $message = $update['message'];

        Log::info('Telegram chat', $message);

        if ($this->hasNewChatMembers($message)) {
            $this->memberSynchronizer->sync($message);

            return;
        }

        /*
         * /start must work before staff authorization.
         */
        if ($this->isStartCommand($message)) {
            $this->startCommandHandler->handle($message);

            return;
        }

        /*
         * Restrict ONLY group messages.
         *
         * Private messages are not affected.
         */
        if ($this->isGroupChat($message)) {
            try {
                $staff = $this->staffResolver->resolve($message);

                if (! $staff->hasRole('super-admin')) {
                    Log::info(
                        'Telegram group message ignored: user is not super-admin.',
                        [
                            'telegram_user_id' => $message['from']['id'] ?? null,
                            'message_id' => $message['message_id'] ?? null,
                            'chat_id' => $message['chat']['id'] ?? null,
                        ]
                    );

                    return;
                }
            } catch (\Throwable $e) {
                Log::info(
                    'Telegram group message ignored: staff could not be resolved.',
                    [
                        'telegram_user_id' => $message['from']['id'] ?? null,
                        'message_id' => $message['message_id'] ?? null,
                        'chat_id' => $message['chat']['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]
                );

                return;
            }
        }

        /*
         * Private task-management reply keyboard button.
         */
        if ($this->isTaskManagementButton($message)) {
            $staff = $this->staffResolver->resolve($message);

            $this->commandHandler->handleManagementButton(
                (int) ($message['chat']['id'] ?? 0),
                $staff,
            );

            return;
        }

        /*
         * Other commands
         */
        if ($this->isCommand($message)) {
            $this->commandHandler->handle($message);

            return;
        }

        /*
         * Normal text / voice / etc.
         */
        $this->messageHandler->handle($message);
    }

    private function hasNewChatMembers(array $message): bool
    {
        return ! empty($message['new_chat_members']);
    }

    private function isGroupChat(array $message): bool
    {
        return in_array(
            $message['chat']['type'] ?? null,
            ['group', 'supergroup'],
            true
        );
    }

    private function isStartCommand(array $message): bool
    {
        $text = trim(
            (string) ($message['text'] ?? '')
        );

        return (bool) preg_match(
            '/^\/start(?:@\w+)?(?:\s+.*)?$/',
            $text
        );
    }

    private function isTaskManagementButton(array $message): bool
    {
        return trim((string) ($message['text'] ?? ''))
            === '📋 Vazifalarni boshqarish';
    }

    private function isCommand(array $message): bool
    {
        return isset($message['text'])
            && str_starts_with(
                trim($message['text']),
                '/'
            );
    }
}
