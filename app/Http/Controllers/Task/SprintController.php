<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreSprintRequest;
use App\Http\Requests\Task\UpdateSprintRequest;
use App\Models\Sprint;
use App\Models\Task;
use App\Services\Task\SprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SprintController extends Controller
{
    public function __construct(
        private readonly SprintService $sprintService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->sprintService->paginate(
                min($request->integer('per_page', 20), 100)
            )
        );
    }

    public function store(
        StoreSprintRequest $request
    ): JsonResponse {
        $sprint = $this->sprintService->create(
            data: $request->validated(),
            creator: $request->user(),
        );

        return response()->json([
            'message' => 'Sprint created successfully.',
            'data' => $sprint,
        ], 201);
    }

    public function show(Sprint $sprint): JsonResponse
    {
        return response()->json([
            'data' => $this->sprintService->find(
                $sprint->id
            ),
        ]);
    }

    public function update(
        UpdateSprintRequest $request,
        Sprint $sprint
    ): JsonResponse {
        $sprint = $this->sprintService->update(
            sprint: $sprint,
            data: $request->validated(),
        );

        return response()->json([
            'message' => 'Sprint updated successfully.',
            'data' => $sprint,
        ]);
    }

    public function addTask(
        Sprint $sprint,
        Task $task
    ): JsonResponse {
        abort_unless(
            request()->user()?->can('task.update'),
            403
        );

        $this->sprintService->addTask(
            sprint: $sprint,
            task: $task,
        );

        return response()->json([
            'message' => 'Task added to sprint successfully.',
        ]);
    }

    public function removeTask(
        Sprint $sprint,
        Task $task
    ): JsonResponse {
        abort_unless(
            request()->user()?->can('task.update'),
            403
        );

        $this->sprintService->removeTask(
            sprint: $sprint,
            task: $task,
        );

        return response()->json([
            'message' => 'Task removed from sprint successfully.',
        ]);
    }

    public function destroy(
        Sprint $sprint
    ): JsonResponse {
        abort_unless(
            request()->user()?->can('task.delete'),
            403
        );

        $this->sprintService->delete($sprint);

        return response()->json([
            'message' => 'Sprint deleted successfully.',
        ]);
    }
}
