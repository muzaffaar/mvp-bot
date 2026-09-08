<?php

namespace App\Http\Requests\Staff;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('staff.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'username' => [
                'nullable',
                'string',
                'max:255',
                'unique:staff,username',
            ],

            'login' => [
                'required',
                'string',
                'max:255',
                'unique:staff,login',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],

            'lavozim' => [
                'nullable',
                'string',
                'max:255',
            ],
            'role' => [
                'required',
                Rule::enum(Role::class),
            ],

            'group_chat_id' => ['nullable', 'integer'],
            'group_name' => ['nullable', 'string']
        ];
    }
}
