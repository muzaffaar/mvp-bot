<?php

namespace App\Services\Task;

use App\Models\Sprint;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SprintService
{
    public function paginate(
        int $perPage = 20
    ): LengthAwarePaginator {
        return Sprint::query()
            ->with('creator')
            ->withCount('tasks')
            ->latest()
            ->paginate($perPage);
    }

    public function find(int $id): Sprint
    {
        return Sprint::query()
            ->with([
                'creator',
                'tasks.assignee',
            ])
            ->findOrFail($id);
    }

    public function create(
        array $data,
        Staff $creator
    ): Sprint {
        return Sprint::create([
            ...$data,
            'created_by' => $creator->id,
        ]);
    }

    public function update(
        Sprint $sprint,
        array $data
    ): Sprint {
        $sprint->update($data);

        return $sprint->fresh();
    }

    public function addTask(
        Sprint $sprint,
        Task $task
    ): void {
        DB::transaction(function () use (
            $sprint,
            $task
        ) {
            $sprint->tasks()->syncWithoutDetaching([
                $task->id => [
                    'added_at' => now(),
                ],
            ]);
        });
    }

    public function removeTask(
        Sprint $sprint,
        Task $task
    ): void {
        $sprint->tasks()->updateExistingPivot(
            $task->id,
            [
                'removed_at' => now(),
            ]
        );
    }

    public function delete(Sprint $sprint): void
    {
        $sprint->delete();
    }
}
