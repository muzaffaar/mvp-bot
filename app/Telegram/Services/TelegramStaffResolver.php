<?php

namespace App\Telegram\Services;

use App\Models\Staff;
use App\Telegram\Exceptions\TelegramAuthorizationException;

class TelegramStaffResolver
{
    public function resolve(
        array $message
    ): Staff {
        $telegramUserId  = $message['from']['id']
            ?? null;

        if ($telegramUserId  === null) {
            throw new TelegramAuthorizationException(
                'Telegram chat ID is missing.'
            );
        }

        $staff = Staff::query()
            ->where(
                'telegram_chat_id',
                $telegramUserId
            )
            ->where(
                'status',
                'active'
            )
            ->first();

        if (! $staff) {
            throw new TelegramAuthorizationException(
                'Telegram account is not linked to an active staff account.'
            );
        }

        return $staff;
    }
}
