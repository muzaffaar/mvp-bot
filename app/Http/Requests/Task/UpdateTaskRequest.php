<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tasks.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => [
                'sometimes',
                'string',
                'max:500',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'priority' => [
                'sometimes',
                Rule::enum(TaskPriority::class),
            ],

            'deadline' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'source_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],
        ];
    }
}
