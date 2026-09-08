<?php

namespace App\Services\Task\Context;

use App\Models\Staff;
use App\Models\Task;
use Illuminate\Support\Collection;

class TaskCandidateFinder
{
    /**
     * Return recent active tasks created by this assignor in the current
     * Telegram chat context.
     *
     * A task may have notification messages in several private chats, so the
     * Telegram message link table is the authoritative chat-context boundary.
     */
    public function find(Staff $assignor, int $chatId, int $limit = 12): Collection
    {
        return Task::query()
            ->where('assignor_id', $assignor->id)
            ->whereNull('closed_at')
            ->whereExists(function ($query) use ($chatId) {
                $query->selectRaw('1')
                    ->from('task_telegram_messages as ttm')
                    ->whereColumn('ttm.task_id', 'tasks.id')
                    ->where('ttm.chat_id', $chatId);
            })
            ->with(['assignee'])
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }
}
