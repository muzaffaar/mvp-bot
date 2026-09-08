<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tasks.comment') ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                'max:5000',
            ],
        ];
    }
}
