<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class AssignTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('task.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => [
                'required',
                'integer',
                'exists:staff,id',
            ],

            'reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
