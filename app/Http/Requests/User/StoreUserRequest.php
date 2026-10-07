<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers(),
            ],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }
}
