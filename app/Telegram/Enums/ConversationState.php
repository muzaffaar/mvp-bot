<?php

namespace App\Telegram\Enums;

enum ConversationState: string
{
    case IDLE = 'idle';

    case CREATING_TASK = 'creating_task';

    case AWAITING_ASSIGNEE = 'awaiting_assignee';

    case AWAITING_TASK_CONFIRMATION = 'awaiting_task_confirmation';
}
