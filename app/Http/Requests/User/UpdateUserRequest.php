<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Edit an operator account. Password is optional (blank = keep current);
 * email uniqueness ignores the account being edited. Authorization + the last-admin /
 * self-demotion lockout guards live on the route middleware + controller.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum + admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'nullable', 'string', Password::min(10)->letters()->mixedCase()->numbers()],
            'is_admin' => ['sometimes', 'boolean'],
            // Restricted access (GitHub #28): confine the operator to specific maps.
            'restricted' => ['sometimes', 'boolean'],
            'map_ids' => ['sometimes', 'array'],
            'map_ids.*' => ['integer', 'exists:maps,id'],
        ];
    }
}
