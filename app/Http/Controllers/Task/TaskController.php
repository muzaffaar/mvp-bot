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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $taskService,
    ) {
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
