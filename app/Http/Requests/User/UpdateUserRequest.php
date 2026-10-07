<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Enums\UserStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
         return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => [
                'sometimes',
                'nullable',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers(),
            ],
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ];
    }
}
