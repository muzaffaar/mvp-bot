<?php

namespace App\AI\Services;

use App\Enums\TelegramConversationState;
use App\Models\Staff;
use App\Models\TelegramConversation;
use App\Telegram\Enums\ConversationState;

class ConversationService
{
    public function getOrCreate( 
        Staff $staff,
        int $chatId
    ): TelegramConversation {
        return TelegramConversation::query()
            ->firstOrCreate(
                [
                    'telegram_chat_id' => $chatId,
                ],
                [
                    'staff_id' => $staff->id,
                    'state' => ConversationState::IDLE->value,
                    'context' => [],
                    'last_activity_at' => now(),
                ]
            );
    }

    public function state(
        TelegramConversation $conversation
    ): ConversationState {
        return ConversationState::from(
            $conversation->state
        );
    }

    public function context(
        TelegramConversation $conversation
    ): array {
        return $conversation->context ?? [];
    }

    public function update(
        TelegramConversation $conversation,
        ConversationState $state,
        array $context = []
    ): void {
        $conversation->update([
            'state' => $state->value,
            'context' => $context,
            'last_activity_at' => now(),
        ]);
    }

    public function reset(
        TelegramConversation $conversation
    ): void {
        $conversation->update([
            'state' => ConversationState::IDLE->value,
            'context' => [],
            'last_activity_at' => now(),
        ]);
    }

    public function setState(
        TelegramConversation $conversation,
        TelegramConversationState|string $state,
    ): TelegramConversation {
        $conversation->update([
            'state' => $state instanceof TelegramConversationState
                ? $state->value
                : $state,
            'last_activity_at' => now(),
        ]);

        return $conversation->fresh();
    }

    public function setContext(
        TelegramConversation $conversation,
        array $context,
    ): TelegramConversation {
        $conversation->update([
            'context' => $context,
            'last_activity_at' => now(),
        ]);

        return $conversation->fresh();
    }

    public function mergeContext(
        TelegramConversation $conversation,
        array $context,
    ): TelegramConversation {
        $current = $conversation->context ?? [];

        $conversation->update([
            'context' => array_merge($current, $context),
            'last_activity_at' => now(),
        ]);

        return $conversation->fresh();
    }
}
