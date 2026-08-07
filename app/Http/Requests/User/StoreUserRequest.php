<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Create an operator account. Authorization is enforced by the `admin`
 * middleware on the route, not here. The password floor matches the self-service
 * change: min 10, letters + mixed case + a number (no ->uncompromised()
 * HTTP call for a single-tier internal tool). `is_admin` is validated but applied
 * explicitly in the controller - never mass-assigned.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum + admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)->letters()->mixedCase()->numbers()],
            'is_admin' => ['sometimes', 'boolean'],
            // Restricted access (GitHub #28): confine the operator to specific maps.
            'restricted' => ['sometimes', 'boolean'],
            'map_ids' => ['sometimes', 'array'],
            'map_ids.*' => ['integer', 'exists:maps,id'],
        ];
    }
}
