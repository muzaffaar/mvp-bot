<?php

namespace App\Events;

use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Task $task,
        public readonly TaskStatus $fromStatus,
        public readonly TaskStatus $toStatus,
        public readonly Staff $actor,
        public readonly ?string $message = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
