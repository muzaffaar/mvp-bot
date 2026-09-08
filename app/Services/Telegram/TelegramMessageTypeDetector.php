<?php

namespace App\Services\Telegram;

use App\Enums\TaskSourceType;

class TelegramMessageTypeDetector
{
    public function detect($message): TaskSourceType
    {
        if ($message->text !== null) {
            return TaskSourceType::TEXT;
        }

        if ($message->voice !== null) {
            return TaskSourceType::VOICE;
        }

        if ($message->document !== null) {
            return TaskSourceType::FILE;
        }

        if ($message->photo !== null) {
            return TaskSourceType::PHOTO;
        }

        if ($message->video !== null) {
            return TaskSourceType::VIDEO;
        }

        if ($message->audio !== null) {
            return TaskSourceType::AUDIO;
        }

        return TaskSourceType::OTHER;
    }
}
