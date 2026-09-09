<?php

namespace App\Http\Controllers\Task;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\ChangeTaskStatusRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Services\Task\TaskStatusTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * Kanban column key => the TaskStatus it represents. The single place
     * that maps board columns to lifecycle statuses — reused to both group
     * tasks into columns and to derive which column-to-column drags are
     * legal per task/actor (see allowedKanbanColumns() below).
     */
    private const KANBAN_COLUMNS = [
        'NEW' => TaskStatus::CREATED,
        'ASSIGNED' => TaskStatus::ASSIGNED,
        'ACCEPTED' => TaskStatus::ACCEPTED,
        'IN_PROGRESS' => TaskStatus::IN_PROGRESS,
        'SUBMITTED' => TaskStatus::AWAITING_ACCEPTANCE,
        'APPROVED' => TaskStatus::COMPLETION_APPROVED,
        'CLOSED' => TaskStatus::CLOSED,
    ];

    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskStatusTransitionService $statusTransitionService,
    ) {
    }

    /**
     * Which kanban columns THIS specific task may legally be dragged into
     * by THIS specific actor right now — derived straight from
     * TaskStatusTransitionService::canTransition(), which applies the same
     * per-task assignee/assignor authorization and lifecycle graph the
     * /tasks/{task}/status endpoint itself enforces. A task's status is
     * never "changeable by everyone": this is why the check is per task
     * and per actor, not a single board-wide permission flag.
     *
     * The ASSIGNED column is always excluded as a target: moving a task
     * there means picking who it's assigned to, which a status-only drag
     * can't express — that goes through the dedicated assign flow instead.
     */
    private function allowedKanbanColumns(Task $task, Staff $actor): array
    {
        $allowed = [];

        foreach (self::KANBAN_COLUMNS as $column => $status) {
            if ($column === 'ASSIGNED' || $status === $task->status) {
                continue;
            }

            if ($this->statusTransitionService->canTransition($task, $status, $actor)) {
                $allowed[] = $column;
            }
        }

        return $allowed;
    }

    public function index(Request $request)
    {
        $tasks = Task::query()
            ->with([
                /*
                |--------------------------------------------------------------------------
                | Task people
                |--------------------------------------------------------------------------
                */
                'author',
                'assignor',
                'assignee',

                /*
                |--------------------------------------------------------------------------
                | Comments
                |--------------------------------------------------------------------------
                */
                'comments' => function ($query) {
                    $query
                        ->with('staff')
                        ->orderBy('created_at');
                },

                /*
                |--------------------------------------------------------------------------
                | Task history / logs
                |--------------------------------------------------------------------------
                */
                'logs' => function ($query) {
                    $query
                        ->with([
                            'actor',
                            'fromAssignee',
                            'toAssignee',
                        ])
                        ->latest('created_at');
                },

                /*
                |--------------------------------------------------------------------------
                | Sprints
                |--------------------------------------------------------------------------
                */
                'sprints',
            ])
            ->latest('created_at')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Active staff
        |--------------------------------------------------------------------------
        */
        $staff = Staff::query()
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Kanban columns
        |--------------------------------------------------------------------------
        */
        $tasksByStatus = [
            'NEW' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::CREATED
                )
                ->values(),

            'ASSIGNED' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::ASSIGNED
                )
                ->values(),

            'IN_PROGRESS' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::IN_PROGRESS
                )
                ->values(),

            'SUBMITTED' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::AWAITING_ACCEPTANCE
                )
                ->values(),

            'ACCEPTED' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::ACCEPTED
                )
                ->values(),

            'APPROVED' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::COMPLETION_APPROVED
                )
                ->values(),

            'CLOSED' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->status === TaskStatus::CLOSED
                )
                ->values(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Alert task
        |--------------------------------------------------------------------------
        */
        $alertTask = $tasks
            ->filter(
                fn (Task $task) =>
                    $task->deadline !== null
                    && $task->deadline->isPast()
                    && ! $task->status->isFinal()
            )
            ->sortBy('deadline')
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Task statistics
        |--------------------------------------------------------------------------
        |
        | Useful for dashboard/header/filter counters.
        |
        */
        $taskStatistics = [
            'total' => $tasks->count(),

            'new' => $tasksByStatus['NEW']->count(),

            'assigned' => $tasksByStatus['ASSIGNED']->count(),

            'in_progress' => $tasksByStatus['IN_PROGRESS']->count(),

            'submitted' => $tasksByStatus['SUBMITTED']->count(),

            'accepted' => $tasksByStatus['ACCEPTED']->count(),

            'approved' => $tasksByStatus['APPROVED']->count(),

            'closed' => $tasksByStatus['CLOSED']->count(),

            'overdue' => $tasks
                ->filter(
                    fn (Task $task) =>
                        $task->deadline !== null
                        && $task->deadline->isPast()
                        && ! $task->status->isFinal()
                )
                ->count(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Kanban drag-and-drop
        |--------------------------------------------------------------------------
        |
        | Per task, per current user: which columns it may be dragged into
        | right now. Keyed by task id so the view can attach it to each
        | card without re-deriving anything.
        */
        $actor = $request->user();

        $taskAllowedColumns = $actor
            ? $tasks->mapWithKeys(
                fn (Task $task) => [$task->id => $this->allowedKanbanColumns($task, $actor)]
            )->all()
            : [];

        return view('tasks.index', [
            'page' => 'tasks',

            'title' => 'IMV IB Support — Topshiriqlar',

            /*
            |--------------------------------------------------------------------------
            | Main data
            |--------------------------------------------------------------------------
            */
            'tasks' => $tasks,

            'tasksByStatus' => $tasksByStatus,

            'staff' => $staff,

            'alertTask' => $alertTask,

            'taskStatistics' => $taskStatistics,

            'taskAllowedColumns' => $taskAllowedColumns,

            /*
            |--------------------------------------------------------------------------
            | Shared layout data
            |--------------------------------------------------------------------------
            */
            'data' => [
                'me' => $request->user(),
            ],
        ]);
    }

    public function store(
        StoreTaskRequest $request
    ): JsonResponse {
        /** @var Staff $staff */
        $staff = $request->user();

        $task = $this->taskService->create(
            data: $request->validated(),
            actor: $staff,
        );

        return response()->json([
            'message' => 'Task created successfully.',
            'data' => $task,
        ], 201);
    }

    public function show(Task $task)
    {
        $task->load([
            'author',
            'assignor',
            'assignee',

            'comments' => function ($query) {
                $query
                    ->with('staff')
                    ->orderBy('created_at');
            },

            'logs' => function ($query) {
                $query
                    ->with([
                        'actor',
                        'fromAssignee',
                        'toAssignee',
                    ])
                    ->latest('created_at');
            },

            'sprints',
        ]);

        return view('tasks.show', [
            'task' => $task,
        ]);
    }

    public function update(
        UpdateTaskRequest $request,
        Task $task
    ): JsonResponse {
        /** @var Staff $staff */
        $staff = $request->user();

        $task = $this->taskService->update(
            task: $task,
            data: $request->validated(),
            actor: $staff,
        );

        return response()->json([
            'message' => 'Task updated successfully.',
            'data' => $task,
        ]);
    }

    public function assign(
        AssignTaskRequest $request,
        Task $task
    ): JsonResponse {
        /** @var Staff $actor */
        $actor = $request->user();

        $assignee = Staff::findOrFail(
            $request->integer('assignee_id')
        );

        $task = $this->taskService->assign(
            task: $task,
            assignee: $assignee,
            actor: $actor,
        );

        return response()->json([
            'message' => 'Task assigned successfully.',
            'data' => $task,
        ]);
    }

    public function reassign(
        AssignTaskRequest $request,
        Task $task
    ): JsonResponse {
        /** @var Staff $actor */
        $actor = $request->user();

        $assignee = Staff::findOrFail(
            $request->integer('assignee_id')
        );

        $task = $this->taskService->reassign(
            task: $task,
            assignee: $assignee,
            actor: $actor,
            reason: $request->input('reason'),
        );

        return response()->json([
            'message' => 'Task reassigned successfully.',
            'data' => $task,
        ]);
    }

    public function changeStatus(
        ChangeTaskStatusRequest $request,
        Task $task
    ): JsonResponse {
        /** @var Staff $actor */
        $actor = $request->user();

        $task = $this->taskService->changeStatus(
            task: $task,
            newStatus: TaskStatus::from(
                $request->input('status')
            ),
            actor: $actor,
            message: $request->input('comment'),
        );

        return response()->json([
            'message' => 'Task status changed successfully.',
            'data' => $task,
        ]);
    }

    public function destroy(
        Request $request,
        Task $task
    ): JsonResponse {
        abort_unless(
            $request->user()?->can('task.archive'),
            403
        );

        /** @var Staff $staff */
        $staff = $request->user();

        $this->taskService->delete(
            task: $task,
            actor: $staff,
        );

        return response()->json([
            'message' => 'Task deleted successfully.',
        ]);
    }

    public function accept(
        Request $request,
        Task $task
    ): JsonResponse {
        abort_unless(
            $request->user()?->can('task.accept'),
            403
        );

        /** @var Staff $staff */
        $staff = $request->user();

        $task = $this->taskService->accept(
            task: $task,
            staff: $staff,
        );

        return response()->json([
            'message' => 'Task accepted successfully.',
            'data' => $task,
        ]);
    }
}
