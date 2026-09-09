<?php

namespace App\Enums;

enum TelegramConversationState: string
{
    case IDLE = 'idle';

    case CREATING_TASK = 'creating_task';

    case WAITING_TASK_COMPLETION_COMMENT =
        'waiting_task_completion_comment';

    case WAITING_TASK_MANAGEMENT_INPUT =
        'waiting_task_management_input';

    case WAITING_TASK_MANAGEMENT_COMMENT =
        'waiting_task_management_comment';

    case WAITING_TASK_MANAGEMENT_ASSIGNEE_SEARCH =
        'waiting_task_management_assignee_search';
}
