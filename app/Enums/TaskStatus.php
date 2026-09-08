<?php

namespace App\Enums;

enum TaskStatus: string
{
    case CREATED = 'created';
    case ASSIGNED = 'assigned';
    case ACCEPTED = 'accepted';
    case IN_PROGRESS = 'in_progress';
    case AWAITING_ACCEPTANCE = 'awaiting_acceptance';
    case COMPLETION_APPROVED = 'completion_approved';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Yaratildi',
            self::ASSIGNED => 'Biriktirildi',
            self::IN_PROGRESS => 'Jarayonda',
            self::AWAITING_ACCEPTANCE => 'Qabul kutilmoqda',
            self::ACCEPTED => 'Qabul qilindi',
            self::CLOSED => 'Yopildi',
            self::CANCELLED => 'Bekor qilindi',
            self::COMPLETION_APPROVED => 'Yakun tasdiqlandi',
            self::RETURNED => 'Qaytarildi',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::CLOSED,
            self::CANCELLED => true,

            default => false,
        };
    }

    public function isAssigneeActionable(): bool
    {
        return match ($this) {
            self::ASSIGNED,
            self::ACCEPTED,
            self::IN_PROGRESS => true,

            default => false,
        };
    }
}
