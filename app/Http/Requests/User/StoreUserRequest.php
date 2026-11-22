<?php

namespace App\Http\Requests\User;

use App\Support\SsoSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Create an operator account. Authorization is enforced by the `admin`
 * middleware on the route, not here. The password floor matches the self-service
 * change: min 10, letters + mixed case + a number (no ->uncompromised()
 * HTTP call for a single-tier internal tool). `is_admin` is validated but applied
 * explicitly in the controller - never mass-assigned.
 *
 * `sso_only` creates an account with no password, set up in advance for someone who will sign in
 * with single sign-on: the first SSO sign-in with that email links to it, keeping the maps and
 * groups given here. Only while SSO is set up (it needn't be switched on yet).
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
            'sso_only' => ['sometimes', 'boolean'],
            'password' => [
                Rule::requiredIf(! $this->boolean('sso_only')),
                'prohibited_if:sso_only,true',
                'nullable',
                'string',
                Password::min(10)->letters()->mixedCase()->numbers(),
            ],
            'is_admin' => ['sometimes', 'boolean'],
            // Restricted access (GitHub #28): confine the operator to specific maps.
            'restricted' => ['sometimes', 'boolean'],
            'passkey_exempt' => ['sometimes', 'boolean'],
            'map_ids' => ['sometimes', 'array'],
            'map_ids.*' => ['integer', 'exists:maps,id'],
            // Named groups (GitHub #28) - their access applies on top, see User::isRestricted().
            'group_ids' => ['sometimes', 'array'],
            'group_ids.*' => ['integer', 'exists:user_groups,id'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('sso_only') && ! app(SsoSettings::class)->configured()) {
                    $validator->errors()->add('sso_only', 'Set up single sign-on (Settings > Security) before adding SSO-only accounts.');
                }
            },
        ];
    }
}
