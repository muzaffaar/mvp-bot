<?php

namespace App\Http\Controllers\Task;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreTaskCommentRequest;
use App\Models\Task;
use App\Services\Task\TaskCommentService;
use Illuminate\Http\JsonResponse;

class TaskCommentController extends Controller
{
    public function __construct(
        private readonly TaskCommentService $commentService,
    ) {
    }

    public function index(Task $task): JsonResponse
    {
        return response()->json([
            'data' => $task->comments()
                ->with('staff')
                ->latest()
                ->paginate(20),
        ]);
    }

    public function store(
        StoreTaskCommentRequest $request,
        Task $task
    ): JsonResponse {
        $comment = $this->commentService->create(
            task: $task,
            staff: $request->user(),
            body: $request->validated('body'),
        );

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => $comment->load('staff'),
        ], 201);
    }
}
