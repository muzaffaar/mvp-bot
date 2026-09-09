<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Max results returned per group (tasks / staff).
     */
    private const LIMIT = 6;

    /**
     * Global topbar search: matches tasks by number/title and staff by
     * full name. Used by the topbar search input on every page.
     */
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([
                'tasks' => [],
                'staff' => [],
            ]);
        }

        /** @var Staff $viewer */
        $viewer = $request->user();

        $tasks = $viewer->can('task.view')
            ? Task::query()
                ->where(function ($builder) use ($query) {
                    $builder
                        ->where('title', 'like', "%{$query}%")
                        ->orWhere('task_number', 'like', "%{$query}%");
                })
                ->latest()
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Task $task) => [
                    'id' => (string) $task->id,
                    'number' => $task->task_number ?? ('TASK-' . $task->id),
                    'title' => $task->title,
                    'status' => $task->status?->value,
                    'url' => route('tasks.index', ['open' => $task->id]),
                ])
                ->values()
            : collect();

        $staff = $viewer->can('staff.view')
            ? Staff::query()
                ->where('full_name', 'like', "%{$query}%")
                ->orderBy('full_name')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Staff $person) => [
                    'id' => (string) $person->id,
                    'name' => $person->full_name,
                    'position' => $person->lavozim,
                    'url' => route('staff.show', $person->id),
                ])
                ->values()
            : collect();

        return response()->json([
            'tasks' => $tasks,
            'staff' => $staff,
        ]);
    }
}
