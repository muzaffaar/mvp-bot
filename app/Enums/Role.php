<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case Head = 'head';
    case Executor = 'executor';
    case Observer = 'observer';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Head => 'Head',
            self::Executor => 'Executor',
            self::Observer => 'Observer',
            self::Auditor => 'Auditor',
        };
    }
}
