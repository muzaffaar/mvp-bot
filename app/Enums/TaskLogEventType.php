<?php

namespace App\Enums;

enum TaskLogEventType: string
{
    case CREATED = 'created';
    case ASSIGNED = 'assigned';
    case REASSIGNED = 'reassigned';
    case STATUS_CHANGED = 'status_changed';
    case STARTED = 'started';
    case COMPLETED = 'completed';
    case ACCEPTED = 'accepted';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';
    case REOPENED = 'reopened';
    case DEADLINE_CHANGED = 'deadline_changed';
    case PRIORITY_CHANGED = 'priority_changed';
    case COMMENTED = 'commented';
    case PUBLISHED_TO_GROUP = 'published_to_group';
}
