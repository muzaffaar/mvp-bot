<?php

namespace App\Enums;

enum TaskSourceType: string
{
    case TEXT = 'text';
    case VOICE = 'voice';
    case FILE = 'file';
    case PHOTO = 'photo';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case OTHER = 'other';
}
