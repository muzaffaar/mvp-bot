<?php

namespace App\Enums;

enum SecurityLogEventType: string
{
    case LOGIN_SUCCESS = 'login_success';
    case LOGIN_FAILED = 'login_failed';
    case LOGOUT = 'logout';
    case PASSWORD_CHANGED = 'password_changed';
    case SESSION_REVOKED = 'session_revoked';

    public function label(): string
    {
        return match ($this) {
            self::LOGIN_SUCCESS => 'Tizimga kirish',
            self::LOGIN_FAILED => 'Muvaffaqiyatsiz kirish',
            self::LOGOUT => 'Tizimdan chiqish',
            self::PASSWORD_CHANGED => 'Parol o\'zgartirildi',
            self::SESSION_REVOKED => 'Sessiya o\'chirildi',
        };
    }
}
