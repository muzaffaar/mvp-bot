<?php

namespace App\Http\Controllers;

use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Business-rule norm for how many active tasks a single staff
     * member is expected to carry. Not backed by a DB column — there
     * is no per-staff capacity setting in this app yet.
     */
    private const WORKLOAD_CAPACITY = 8;

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
            ->orderBy('full_name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Tasks
        |--------------------------------------------------------------------------
        |
        | Do NOT filter tasks by the authenticated user's group.
        |
        | Dashboard permission grants access to the dashboard dataset.
        |
        | `assignee`, `logs` and `comments` are used by buildStatistics()
        | itself. `author`, `assignor` and `sprints` are eager-loaded too
        | because the attention-card task lists (waiting acceptance,
        | deadline risk, unassigned) feed the same task detail drawer used
        | on the Tasks page, which needs those relations to render.
        |
        */

        $tasks = Task::query()
            ->with([
                'assignee',
                'author',
                'assignor',
                'logs' => function ($query) {
                    $query
                        ->with([
                            'actor',
                            'fromAssignee',
                            'toAssignee',
                        ])
                        ->latest('created_at');
                },
                'comments' => function ($query) {
                    $query
                        ->with('staff')
                        ->orderBy('created_at');
                },
                'sprints',
            ])
            ->latest()
            ->get();

        $stats = $this->buildStatistics(
            $tasks,
            $groupMembers
        );

        return view(
            'dashboard.index',
            compact('stats')
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

                /*
                 * Signed diff (absolute = false): a negative value means
                 * completed_at is before the start timestamp, which is
                 * corrupt data that must be excluded, not silently
                 * flipped positive and left to pollute the median.
                 */
                return $start->diffInMinutes(
                    $task->completed_at,
                    false
                );
            })
            ->filter(
                fn ($minutes) =>
                    $minutes !== null
                    && $minutes >= 0
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

                        'capacity' => self::WORKLOAD_CAPACITY,
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
                            $task->started_at,
                            false
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
        | Automation accuracy
        |--------------------------------------------------------------------------
        |
        | Real average of the AI-extracted confidence score
        | (ajralish_aniqligi) across every task that has one, instead of
        | a hardcoded placeholder. Null when no task has this data yet,
        | so the UI can show "no data" rather than a fake percentage.
        */

        $confidenceScores = $tasks
            ->pluck('ajralish_aniqligi')
            ->filter(fn ($value) => $value !== null);

        $automationAccuracy = $confidenceScores->isNotEmpty()
            ? (int) round($confidenceScores->avg())
            : null;

        /*
        |--------------------------------------------------------------------------
        | Telegram integration activity
        |--------------------------------------------------------------------------
        |
        | Real signal instead of a hardcoded "true": has ANY task actually
        | arrived through Telegram in the last 24 hours.
        */

        $telegramActive = $tasks->contains(
            fn (Task $task) =>
                $task->source_message_id !== null
                && $task->created_at !== null
                && $task->created_at->gte($now->copy()->subDay())
        );

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
            | Attention task models
            |--------------------------------------------------------------------------
            |
            | Full Task models (not the trimmed smallTaskPayload() arrays above)
            | for every task shown in the three attention-card lists, so the
            | dashboard can open the same task detail drawer the Tasks page
            | uses when one of those tasks is clicked.
            */

            'attention_task_models' => $waitingAcceptance
                ->merge($deadlineRisk)
                ->merge($unassigned)
                ->unique('id')
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

                'telegram_active' => $telegramActive,

                'automation_accuracy' => $automationAccuracy,

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

            'created_at' => $task->created_at?->toISOString(),
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
}
