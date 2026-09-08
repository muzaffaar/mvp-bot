<?php

namespace App\Enums;

enum TaskAssignmentType: string
{
    case DIRECT = 'direct';
    case GROUP = 'group';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT => 'Direct',
            self::GROUP => 'Group',
        };
    }
}
