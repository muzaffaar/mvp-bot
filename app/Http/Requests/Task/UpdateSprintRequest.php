<?php

namespace App\Http\Requests\Task;

use App\Enums\SprintStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tasks.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'start_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'end_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],

            'status' => [
                'sometimes',
                Rule::enum(SprintStatus::class),
            ],
        ];
    }
}
