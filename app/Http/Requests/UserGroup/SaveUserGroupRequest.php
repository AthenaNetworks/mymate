<?php

namespace App\Http\Requests\UserGroup;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit an operator group (GitHub #28). Admin-only via the route middleware. On an edit
 * every field is optional so the SPA can send just what changed; `restricted`, `map_ids` and
 * `user_ids` are applied explicitly in the controller, never mass-assigned.
 */
class SaveUserGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum + admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $group = $this->route('userGroup');
        $sometimes = $group ? ['sometimes'] : [];

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:255', Rule::unique('user_groups', 'name')->ignore($group?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'restricted' => ['sometimes', 'boolean'],
            'map_ids' => ['sometimes', 'array'],
            'map_ids.*' => ['integer', 'exists:maps,id'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    /** Admins see everything regardless, so putting one in a group would only be misleading. */
    public function after(): array
    {
        return [
            function (Validator $v): void {
                $ids = array_map('intval', (array) $this->input('user_ids', []));
                if ($ids !== [] && User::query()->whereIn('id', $ids)->where('is_admin', true)->exists()) {
                    $v->errors()->add('user_ids', 'Administrators see everything and can\'t be put in a group.');
                }
            },
        ];
    }
}
