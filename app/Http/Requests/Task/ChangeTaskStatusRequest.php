<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tasks.update') ?? false;
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
