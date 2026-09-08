<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskAssignmentType;
use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tasks.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'group_id' => [
                'required',
                'integer',
                'exists:groups,id',
            ],

            'assignee_id' => [
                'nullable',
                'integer',
                'exists:staff,id',
            ],

            'assignment_type' => [
                'required',
                Rule::enum(TaskAssignmentType::class),
            ],

            'title' => [
                'required',
                'string',
                'max:500',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'priority' => [
                'nullable',
                Rule::enum(TaskPriority::class),
            ],

            'deadline' => [
                'nullable',
                'date',
            ],

            'source_type' => [
                'nullable',
                'string',
                'max:30',
            ],

            'source_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'source_message_id' => [
                'nullable',
                'string',
                'max:255',
            ],

            'source_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'ajralish_aniqligi' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
        ];
    }
}
