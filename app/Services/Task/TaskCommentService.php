<?php

namespace App\Services\Task;

use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

class TaskCommentService
{
    public function create(
        Task $task,
        Staff $staff,
        string $body
    ): TaskComment {
        return DB::transaction(function () use (
            $task,
            $staff,
            $body
        ) {
            return $task->comments()->create([
                'staff_id' => $staff->id,
                'body' => $body,
            ]);
        });
    }

    public function delete(
        TaskComment $comment
    ): void {
        $comment->delete();
    }
}
