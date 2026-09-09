<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        /*
         * The permission required depends on which transition is
         * being requested, since not every role that can update a
         * task is also allowed to cancel it.
         *
         * NOTE: `task.start` is intentionally NOT used here. This
         * endpoint is the panel-only status-change action (the
         * Telegram bot's own start/submit/accept flow goes through
         * TaskStatusTransitionService instead, with its own
         * permission checks). `task.start` is only granted to the
         * `executor` role, which cannot log into the admin panel at
         * all — gating on it here would make every panel status
         * change (including "start work") unreachable.
         */
        return match (TaskStatus::tryFrom($this->input('status'))) {
            TaskStatus::CANCELLED => $user->can('task.cancel'),
            default => $user->can('task.update'),
        };
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::enum(TaskStatus::class),
            ],

            'comment' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];
    }
}
