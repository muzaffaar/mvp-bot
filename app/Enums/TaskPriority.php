<?php

namespace App\Enums;

enum TaskPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Past',
            self::NORMAL => 'Oddiy',
            self::HIGH => 'Yuqori',
            self::URGENT => 'Juda yuqori',
        };
    }
}
