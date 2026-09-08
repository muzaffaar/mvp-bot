<?php

namespace App\Enums;

enum SprintStatus: string
{
    case PLANNED = 'planned';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
