<?php

namespace App\Http\Controllers\Report;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportsController extends Controller
{
    public function index(): View
    {
        $statusCounts = Task::query()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $statusCounts->sum();

        $completed = (int) (
            ($statusCounts[TaskStatus::ACCEPTED->value] ?? 0)
            + ($statusCounts[TaskStatus::CLOSED->value] ?? 0)
        );

        $finalStatuses = [
            TaskStatus::CLOSED->value,
            TaskStatus::CANCELLED->value,
        ];

        $overdue = Task::query()
            ->whereNotNull('deadline')
            ->where('deadline', '<', now())
            ->whereNotIn('status', $finalStatuses)
            ->count();

        $unassigned = Task::query()
            ->whereNull('assignee_id')
            ->count();

        $completionRate = $total > 0
            ? (int) round($completed / $total * 100)
            : 0;

        $statusBreakdown = collect(TaskStatus::cases())
            ->map(function (TaskStatus $status) use ($statusCounts, $total) {
                $count = (int) ($statusCounts[$status->value] ?? 0);

                return [
                    'label' => $status->label(),
                    'count' => $count,
                    'percentage' => $total > 0
                        ? (int) round($count / $total * 100)
                        : 0,
                ];
            })
            ->values();

        $workload = Staff::query()
            ->where('status', 'active')
            ->withCount([
                'assignedTasks as active_tasks_count' => function ($query) use ($finalStatuses) {
                    $query->whereNotIn('status', $finalStatuses);
                },

                'assignedTasks as overdue_tasks_count' => function ($query) use ($finalStatuses) {
                    $query
                        ->whereNotNull('deadline')
                        ->where('deadline', '<', now())
                        ->whereNotIn('status', $finalStatuses);
                },
            ])
            ->orderByDesc('active_tasks_count')
            ->get();

        $maxWorkload = (int) $workload->max('active_tasks_count');

        return view('reports.index', [
            'page' => 'reports',
            'title' => 'IMV IB Support — Hisobotlar',

            'total' => $total,
            'overdue' => $overdue,
            'unassigned' => $unassigned,
            'completionRate' => $completionRate,

            'statusBreakdown' => $statusBreakdown,

            'workload' => $workload,
            'maxWorkload' => max($maxWorkload, 1),
        ]);
    }
}
