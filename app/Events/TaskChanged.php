<?php

namespace App\Events;

use App\Models\Staff;
use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly Staff $actor,
        public readonly array $changes,
    ) {
    }
}
