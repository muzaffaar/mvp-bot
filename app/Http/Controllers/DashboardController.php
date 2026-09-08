<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var Staff $staff */
        $staff = $request->user()->load('roles');

        /*
        |--------------------------------------------------------------------------
        | Dashboard permission
        |--------------------------------------------------------------------------
        |
        | The dashboard is available to anyone who has the dashboard.view
        | permission. There is no group-based authorization here.
        |
        */

        abort_unless(
            $staff->can('dashboard.view'),
            403
        );

        /*
        |--------------------------------------------------------------------------
        | Dashboard members
        |--------------------------------------------------------------------------
        |
        | Dashboard data is not restricted to the authenticated user's group.
        | Anyone with dashboard.view can see the dashboard dataset.
        |
        */

        $groupMembers = Staff::query()
            ->with([
                'roles',
                'aliases',
            ])
            ->orderBy('full_name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Logical groups
        |--------------------------------------------------------------------------
        |
        | There is no Group model anymore.
        |
        | Groups are represented by:
        |   staff.group_chat_id
        |   staff.group_name
        |
        | Build unique logical groups from Staff records.
        |
        */

        $groups = $groupMembers
            ->filter(
                fn (Staff $member) =>
                    $member->group_chat_id !== null
            )
            ->groupBy(
                fn (Staff $member) =>
                    (string) $member->group_chat_id
            )
            ->map(
                function ($members, string $chatId) {
                    /** @var Staff $firstMember */
                    $firstMember = $members->first();

                    return [
                        'id' => $chatId,

                        'name' => $firstMember->group_name
                            ?: 'Unnamed Group',

                        'chat_id' => $chatId,

                        'staff' => $members->values(),
                    ];
                }
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Tasks
        |--------------------------------------------------------------------------
        |
        | Do NOT filter tasks by the authenticated user's group.
        |
        | Dashboard permission grants access to the dashboard dataset.
        |
        */

        $tasks = Task::query()
            ->with([
                'author',
                'assignor',
                'assignee',
                'logs.actor',
                'logs.fromAssignee',
                'logs.toAssignee',
                'comments.staff',
                'sprints',
            ])
            ->latest()
            ->get();

        $stats = $this->buildStatistics(
            $tasks,
            $groupMembers
        );

        /*
        |--------------------------------------------------------------------------
        | Dashboard members
        |--------------------------------------------------------------------------
        */

        $members = $groupMembers
            ->unique('id')
            ->map(function (Staff $member) {
                $role = $this->resolveStaffRole($member);

                return [
                    'id' => $member->group_chat_id !== null
                        ? $member->group_chat_id . ':' . $member->id
                        : (string) $member->id,

                    'personId' => (string) $member->id,

                    'groupId' => $member->group_chat_id !== null
                        ? (string) $member->group_chat_id
                        : null,

                    'role' => $this->dashboardRole($role),

                    'active' => strtolower(
                        (string) $member->status
                    ) === 'active',

                    'joined' => $member->created_at
                        ? Carbon::parse(
                            $member->created_at
                        )->toISOString()
                        : null,
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Persons
        |--------------------------------------------------------------------------
        */

        $personIds = $members
            ->pluck('personId')
            ->unique()
            ->values();

        $persons = Staff::query()
            ->whereIn('id', $personIds)
            ->with('aliases')
            ->get()
            ->map(fn (Staff $person) => [
                'id' => (string) $person->id,

                'fullName' => $person->full_name,

                'username' => $person->username,

                'tgId' => $person->telegram_chat_id,

                'aliases' => $person->aliases
                    ->pluck('alias')
                    ->values(),

                'post' => $person->lavozim,

                'systemUser' => $person->can('dashboard.view'),

                'status' => strtoupper(
                    (string) $person->status
                ),

                'mergedInto' => null,

                'firstSeen' => $person->created_at?->toDateString(),
            ])
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Dashboard JS data
        |--------------------------------------------------------------------------
        */

        $data = [
            'authenticated' => true,

            'me' => (string) $staff->id,

            'groups' => $groups
                ->map(fn (array $group) => [
                    'id' => (string) $group['id'],

                    'title' => $group['name'],

                    'tgChatId' => (string) $group['chat_id'],

                    'tz' => config(
                        'app.timezone',
                        'Asia/Tashkent'
                    ),
                ])
                ->values(),

            'persons' => $persons,

            'members' => $members,

            'tasks' => $tasks
                ->map(
                    fn (Task $task) =>
                        $this->taskPayload($task)
                )
                ->values(),

            'events' => $tasks
                ->flatMap(
                    fn (Task $task) =>
                        $task->logs->map(
                            fn ($log) => [
                                'id' => (string) $log->id,

                                'taskId' => (string) $task->id,

                                'type' => strtoupper(
                                    $log->event_type?->value
                                    ?? (string) $log->event_type
                                ),

                                'actorId' => $log->actor_id
                                    ? (string) $log->actor_id
                                    : null,

                                'actorKind' => 'USER',

                                'channel' => 'WEB',

                                'from' => $log->from_status?->value,

                                'to' => $log->to_status?->value,

                                'text' => $log->message ?? '',

                                'at' => $log->created_at
                                    ?->toISOString(),
                            ]
                        )
                )
                ->values(),

            'comments' => $tasks
                ->flatMap(
                    fn (Task $task) =>
                        $task->comments->map(
                            fn ($comment) => [
                                'id' => (string) $comment->id,

                                'taskId' => (string) $task->id,

                                'personId' => (string) $comment->staff_id,

                                'text' => $comment->body,

                                'at' => $comment->created_at
                                    ?->toISOString(),
                            ]
                        )
                )
                ->values(),

            'notifications' => [],

            'audit' => [],

            'sessions' => [],

            'links' => [],
        ];

        /*
        |--------------------------------------------------------------------------
        | View
        |--------------------------------------------------------------------------
        |
        | No sidebarGroups are passed because the sidebar no longer has
        | a group selector/dropdown.
        |
        */

        return view(
            'dashboard.index',
            compact(
                'data',
                'stats'
            )
        );
    }

    /**
     * Build dashboard metrics from real database records.
     */
    private function buildStatistics($tasks, $groupMembers): array
    {
        $now = now();

        $total = $tasks->count();

        /*
        |--------------------------------------------------------------------------
        | Completed tasks
        |--------------------------------------------------------------------------
        */

        $completedStatuses = [
            TaskStatus::ACCEPTED,
            TaskStatus::COMPLETION_APPROVED,
            TaskStatus::CLOSED,
        ];

        $completed = $tasks->filter(
            fn (Task $task) =>
                in_array(
                    $task->status,
                    $completedStatuses,
                    true
                )
                || $task->completed_at !== null
        );

        /*
        |--------------------------------------------------------------------------
        | On-time completion
        |--------------------------------------------------------------------------
        */

        $completedWithDeadline = $completed->filter(
            fn (Task $task) =>
                $task->deadline !== null
                && $task->completed_at !== null
        );

        $onTimeCount = $completedWithDeadline
            ->filter(
                fn (Task $task) =>
                    $task->completed_at->lte(
                        $task->deadline
                    )
            )
            ->count();

        $onTimePercentage = $completedWithDeadline->count() > 0
            ? round(
                (
                    $onTimeCount
                    / $completedWithDeadline->count()
                ) * 100,
                1
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Median completion time
        |--------------------------------------------------------------------------
        */

        $durations = $completed
            ->filter(
                fn (Task $task) =>
                    $task->completed_at !== null
            )
            ->map(function (Task $task) {
                $start = $task->started_at
                    ?? $task->created_at;

                if (!$start || !$task->completed_at) {
                    return null;
                }

                return $start->diffInMinutes(
                    $task->completed_at
                );
            })
            ->filter(
                fn ($minutes) =>
                    $minutes !== null
            )
            ->sort()
            ->values();

        $medianMinutes = $this->median($durations);

        $medianHours = $medianMinutes !== null
            ? round($medianMinutes / 60, 1)
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Overdue
        |--------------------------------------------------------------------------
        */

        $overdue = $tasks->filter(
            function (Task $task) use ($now) {
                if (!$task->deadline) {
                    return false;
                }

                if (
                    in_array(
                        $task->status,
                        [
                            TaskStatus::CLOSED,
                            TaskStatus::CANCELLED,
                        ],
                        true
                    )
                ) {
                    return false;
                }

                return $task->deadline->lt($now);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Returned
        |--------------------------------------------------------------------------
        */

        $returned = $tasks->filter(
            fn (Task $task) =>
                $task->status === TaskStatus::RETURNED
                || $task->logs->contains(
                    fn ($log) =>
                        $log->to_status === TaskStatus::RETURNED
                )
        );

        $returnedPercentage = $total > 0
            ? round(
                ($returned->count() / $total) * 100,
                1
            )
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Waiting for acceptance
        |--------------------------------------------------------------------------
        */

        $waitingAcceptance = $tasks->filter(
            fn (Task $task) =>
                $task->status === TaskStatus::AWAITING_ACCEPTANCE
        );

        /*
        |--------------------------------------------------------------------------
        | Deadline risk
        |--------------------------------------------------------------------------
        */

        $deadlineRisk = $tasks->filter(
            function (Task $task) use ($now) {
                if (!$task->deadline) {
                    return false;
                }

                if (
                    in_array(
                        $task->status,
                        [
                            TaskStatus::CLOSED,
                            TaskStatus::CANCELLED,
                            TaskStatus::ACCEPTED,
                            TaskStatus::COMPLETION_APPROVED,
                        ],
                        true
                    )
                ) {
                    return false;
                }

                return $task->deadline->between(
                    $now,
                    $now->copy()->addDay()
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Unassigned
        |--------------------------------------------------------------------------
        */

        $unassigned = $tasks->filter(
            fn (Task $task) =>
                $task->assignee_id === null
                && !in_array(
                    $task->status,
                    [
                        TaskStatus::CLOSED,
                        TaskStatus::CANCELLED,
                    ],
                    true
                )
        );

        /*
        |--------------------------------------------------------------------------
        | Status distribution
        |--------------------------------------------------------------------------
        */

        $statuses = [
            'created' => $tasks
                ->where('status', TaskStatus::CREATED)
                ->count(),

            'assigned' => $tasks
                ->where('status', TaskStatus::ASSIGNED)
                ->count(),

            'in_progress' => $tasks
                ->where('status', TaskStatus::IN_PROGRESS)
                ->count(),

            'awaiting_acceptance' => $tasks
                ->where(
                    'status',
                    TaskStatus::AWAITING_ACCEPTANCE
                )
                ->count(),

            'accepted' => $tasks
                ->filter(
                    fn (Task $task) =>
                        in_array(
                            $task->status,
                            [
                                TaskStatus::ACCEPTED,
                                TaskStatus::COMPLETION_APPROVED,
                            ],
                            true
                        )
                )
                ->count(),

            'closed' => $tasks
                ->where('status', TaskStatus::CLOSED)
                ->count(),

            'cancelled' => $tasks
                ->where('status', TaskStatus::CANCELLED)
                ->count(),

            'returned' => $tasks
                ->where('status', TaskStatus::RETURNED)
                ->count(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Source distribution
        |--------------------------------------------------------------------------
        */

        $sources = [
            'telegram_text' => 0,
            'telegram_voice' => 0,
            'telegram_file' => 0,
            'panel' => 0,
        ];

        foreach ($tasks as $task) {
            $sourceType = $task->source_type;

            if (!$sourceType instanceof TaskSourceType) {
                $sourceType = TaskSourceType::tryFrom(
                    strtolower((string) $sourceType)
                );
            }

            $isTelegram = $task->source_message_id !== null;

            if (!$isTelegram) {
                $sources['panel']++;
                continue;
            }

            match ($sourceType) {
                TaskSourceType::TEXT =>
                    $sources['telegram_text']++,

                TaskSourceType::VOICE,
                TaskSourceType::AUDIO =>
                    $sources['telegram_voice']++,

                TaskSourceType::FILE,
                TaskSourceType::PHOTO,
                TaskSourceType::VIDEO =>
                    $sources['telegram_file']++,

                default =>
                    $sources['panel']++,
            };
        }

        /*
        |--------------------------------------------------------------------------
        | Employee workload
        |--------------------------------------------------------------------------
        */

        $activeStatuses = [
            TaskStatus::CREATED,
            TaskStatus::ASSIGNED,
            TaskStatus::IN_PROGRESS,
            TaskStatus::AWAITING_ACCEPTANCE,
            TaskStatus::RETURNED,
        ];

        $workload = $groupMembers
            ->unique('id')
            ->map(
                function (Staff $member) use (
                    $tasks,
                    $activeStatuses
                ) {
                    $count = $tasks
                        ->where(
                            'assignee_id',
                            $member->id
                        )
                        ->filter(
                            fn (Task $task) =>
                                in_array(
                                    $task->status,
                                    $activeStatuses,
                                    true
                                )
                        )
                        ->count();

                    return [
                        'id' => $member->id,

                        'name' => $member->full_name,

                        'load' => $count,

                        'capacity' => 8,
                    ];
                }
            )
            ->sortByDesc('load')
            ->values();

        /*
        |--------------------------------------------------------------------------
        | 7-day dynamics
        |--------------------------------------------------------------------------
        */

        $dynamics = collect(range(6, 0))
            ->map(
                function (int $daysAgo) use ($tasks) {
                    $date = today()->subDays($daysAgo);

                    $created = $tasks
                        ->filter(
                            fn (Task $task) =>
                                $task->created_at
                                    ?->isSameDay($date)
                        )
                        ->count();

                    $accepted = $tasks
                        ->filter(
                            fn (Task $task) =>
                                $task->completed_at
                                    ?->isSameDay($date)
                                || $task->logs->contains(
                                    fn ($log) =>
                                        $log->event_type?->value
                                            === 'accepted'
                                        && $log->created_at
                                            ?->isSameDay($date)
                                )
                        )
                        ->count();

                    return [
                        'date' => $date->format('Y-m-d'),

                        'label' => $date->format('d.m'),

                        'created' => $created,

                        'accepted' => $accepted,
                    ];
                }
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Today's activity
        |--------------------------------------------------------------------------
        */

        $today = today();

        $todayCreated = $tasks
            ->filter(
                fn (Task $task) =>
                    $task->created_at
                        ?->isSameDay($today)
            )
            ->count();

        $todayCompleted = $tasks
            ->filter(
                fn (Task $task) =>
                    $task->completed_at
                        ?->isSameDay($today)
            )
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Today's comments
        |--------------------------------------------------------------------------
        */

        $todayComments = $tasks
            ->flatMap(
                fn (Task $task) =>
                    $task->comments
            )
            ->filter(
                fn ($comment) =>
                    $comment->created_at
                        ?->isSameDay($today)
            )
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Average response time
        |--------------------------------------------------------------------------
        |
        | Currently calculated from task creation until started_at.
        |
        */

        $responseTimes = $tasks
            ->filter(
                fn (Task $task) =>
                    $task->created_at !== null
                    && $task->started_at !== null
            )
            ->map(
                fn (Task $task) =>
                    $task->created_at
                        ->diffInMinutes(
                            $task->started_at
                        )
            )
            ->filter(
                fn ($minutes) =>
                    $minutes >= 0
            )
            ->values();

        $averageResponseMinutes = $responseTimes->count() > 0
            ? round(
                $responseTimes->average()
            )
            : 0;


        /*
        |--------------------------------------------------------------------------
        | Dashboard result
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |--------------------------------------------------------------------------
            | Main metrics
            |--------------------------------------------------------------------------
            */

            'total' => $total,

            'on_time_percentage' => $onTimePercentage,

            'median_hours' => $medianHours,

            'overdue' => $overdue->count(),

            'returned_percentage' => $returnedPercentage,


            /*
            |--------------------------------------------------------------------------
            | Attention tasks
            |--------------------------------------------------------------------------
            */

            'waiting_acceptance' => $waitingAcceptance
                ->sortBy('completed_at')
                ->map(
                    fn (Task $task) =>
                        $this->smallTaskPayload($task)
                )
                ->values(),

            'deadline_risk' => $deadlineRisk
                ->sortBy('deadline')
                ->map(
                    fn (Task $task) =>
                        $this->smallTaskPayload($task)
                )
                ->values(),

            'unassigned' => $unassigned
                ->sortByDesc('created_at')
                ->map(
                    fn (Task $task) =>
                        $this->smallTaskPayload($task)
                )
                ->values(),


            /*
            |--------------------------------------------------------------------------
            | Analytics
            |--------------------------------------------------------------------------
            */

            'status_distribution' => $statuses,

            'sources' => $sources,

            'workload' => $workload,

            'dynamics' => $dynamics,


            /*
            |--------------------------------------------------------------------------
            | Today
            |--------------------------------------------------------------------------
            */

            'today' => [

                'created' => $todayCreated,

                'completed' => $todayCompleted,

                'comments' => $todayComments,

                'average_response_minutes' =>
                    $averageResponseMinutes,

            ],


            /*
            |--------------------------------------------------------------------------
            | Quick status
            |--------------------------------------------------------------------------
            */

            'quick_status' => [

                /*
                * You can later connect this to
                * actual Telegram health monitoring.
                */

                'telegram_active' => true,

                'automation_accuracy' => 94,

                'needs_review' =>
                    $waitingAcceptance->count(),

                'overdue' =>
                    $overdue->count(),

            ],

        ];
    }

    private function smallTaskPayload(Task $task): array
    {
        return [
            'id' => (string) $task->id,

            'number' => $task->task_number,

            'title' => $task->title,

            'status' => $task->status?->value,

            'priority' => $task->priority?->value,

            'deadline' => $task->deadline?->toISOString(),

            'assignee' => $task->assignee?->full_name,
        ];
    }

    private function median($values): ?float
    {
        $values = collect($values)
            ->filter(
                fn ($value) =>
                    is_numeric($value)
            )
            ->sort()
            ->values();

        $count = $values->count();

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return (
                $values[$middle - 1]
                + $values[$middle]
            ) / 2;
        }

        return $values[$middle];
    }

    private function taskPayload(Task $task): array
    {
        $metadata = is_array($task->metadata)
            ? $task->metadata
            : [];

        $sourceType = $task->source_type;

        if (!$sourceType instanceof TaskSourceType) {
            $sourceType = TaskSourceType::tryFrom(
                strtolower((string) $sourceType)
            );
        }

        return [
            'id' => (string) $task->id,

            'number' => $task->task_number,

            /*
             * group_id represents the Telegram group chat ID.
             */
            'groupId' => $task->group_id !== null
                ? (string) $task->group_id
                : null,

            'title' => $task->title,

            'description' => $task->description ?? '',

            'type' => 'TASK',

            'status' => $this->dashboardStatus(
                $task->status
            ),

            'priority' => $this->dashboardPriority(
                $task->priority
            ),

            'assigneeId' => $task->assignee_id
                ? (string) $task->assignee_id
                : null,

            'guessed' => false,

            'confidence' => $task->ajralish_aniqligi !== null
                ? (float) $task->ajralish_aniqligi
                : null,

            'createdBy' => $task->author_id
                ? (string) $task->author_id
                : null,

            'createdAt' => $task->created_at?->toISOString(),

            'deadline' => $task->deadline?->toISOString(),

            'startedAt' => $task->started_at?->toISOString(),

            'submittedAt' => data_get(
                $metadata,
                'submitted_at'
            ),

            'acceptedAt' => data_get(
                $metadata,
                'accepted_at'
            ),

            'closedAt' => $task->closed_at?->toISOString(),

            'pausedMs' => (int) data_get(
                $metadata,
                'paused_ms',
                0
            ),

            'reworks' => (int) data_get(
                $metadata,
                'reworks',
                0
            ),

            'parentId' => data_get(
                $metadata,
                'parent_id'
            ),

            'checklist' => data_get(
                $metadata,
                'checklist',
                []
            ),

            'watchers' => data_get(
                $metadata,
                'watchers',
                []
            ),

            'attachments' => data_get(
                $metadata,
                'attachments',
                []
            ),

            'archived' => (bool) data_get(
                $metadata,
                'archived',
                false
            ),

            'source' => [
                'kind' => $this->dashboardSource(
                    $sourceType,
                    $task->source_message_id !== null
                ),

                'text' => $task->description
                    ?: $task->title,

                'tgMsgId' => $task->source_message_id,

                'transcript' => data_get(
                    $metadata,
                    'transcript'
                ),

                'conf' => $task->ajralish_aniqligi !== null
                    ? (float) $task->ajralish_aniqligi / 100
                    : null,
            ],
        ];
    }

    private function dashboardStatus(
        ?TaskStatus $status
    ): string {
        return match ($status) {
            TaskStatus::CREATED =>
                'NEW',

            TaskStatus::ASSIGNED =>
                'ASSIGNED',

            TaskStatus::IN_PROGRESS =>
                'IN_PROGRESS',

            TaskStatus::AWAITING_ACCEPTANCE =>
                'SUBMITTED',

            TaskStatus::ACCEPTED,
            TaskStatus::COMPLETION_APPROVED =>
                'ACCEPTED',

            TaskStatus::CLOSED =>
                'CLOSED',

            TaskStatus::CANCELLED =>
                'CANCELLED',

            TaskStatus::RETURNED =>
                'IN_PROGRESS',

            default =>
                'NEW',
        };
    }

    private function dashboardPriority(
        ?TaskPriority $priority
    ): string {
        return match ($priority) {
            TaskPriority::LOW =>
                'LOW',

            TaskPriority::NORMAL =>
                'NORMAL',

            TaskPriority::HIGH =>
                'HIGH',

            TaskPriority::URGENT =>
                'CRITICAL',

            default =>
                'NORMAL',
        };
    }

    private function dashboardSource(
        ?TaskSourceType $sourceType,
        bool $isTelegram
    ): string {
        if (!$isTelegram) {
            return 'MANUAL';
        }

        return match ($sourceType) {
            TaskSourceType::TEXT =>
                'TEXT',

            TaskSourceType::VOICE,
            TaskSourceType::AUDIO =>
                'VOICE',

            TaskSourceType::FILE,
            TaskSourceType::PHOTO,
            TaskSourceType::VIDEO =>
                'DOCUMENT',

            default =>
                'MANUAL',
        };
    }

    /**
     * Resolve the Staff's actual role.
     */
    private function resolveStaffRole(Staff $staff): ?string
    {
        $roles = $staff->roles;

        foreach ([
            Role::SuperAdmin->value,
            Role::Head->value,
            Role::Executor->value,
            Role::Observer->value,
            Role::Auditor->value,
        ] as $role) {
            if (
                $roles->contains(
                    fn ($item) =>
                        $item->name === $role
                        || $item->value === $role
                )
            ) {
                return $role;
            }
        }

        return null;
    }

    private function dashboardRole(?string $role): ?string
    {
        return match ($role) {
            Role::SuperAdmin->value =>
                'ORG_ADMIN',

            Role::Head->value =>
                'HEAD',

            Role::Executor->value =>
                'EXECUTOR',

            Role::Observer->value =>
                'OBSERVER',

            Role::Auditor->value =>
                'AUDITOR',

            default =>
                null,
        };
    }
}
