<?php

namespace App\Http\Requests\Map;

use Illuminate\Foundation\Http\FormRequest;

class StoreMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_map_id' => ['nullable', 'integer', 'exists:maps,id'],
            'leaflet_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer'],
        ];
    }
}
