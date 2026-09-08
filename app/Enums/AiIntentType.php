<?php

namespace App\AI\Enums;

enum AiIntentType: string
{
    case CREATE_TASK = 'create_task';
    case ACCEPT_TASK = 'accept_task';
    case ASSIGN_TASK = 'assign_task';
    case REASSIGN_TASK = 'reassign_task';
    case CHANGE_STATUS = 'change_status';
    case COMMENT_TASK = 'comment_task';

    case LIST_MY_TASKS = 'list_my_tasks';
    case LIST_GROUP_TASKS = 'list_group_tasks';
    case GET_TASK = 'get_task';

    case UNKNOWN = 'unknown';
}
