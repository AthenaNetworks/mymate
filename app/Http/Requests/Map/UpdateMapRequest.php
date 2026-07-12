<?php

namespace App\Http\Requests\Map;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_map_id' => ['nullable', 'integer', 'exists:maps,id'],
            'position' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // A map can't be its own parent (a one-level cycle guard).
            $self = $this->route('map');
            $selfId = is_object($self) ? $self->id : $self;
            if ($this->input('parent_map_id') !== null && (int) $this->input('parent_map_id') === (int) $selfId) {
                $v->errors()->add('parent_map_id', 'A map cannot be its own parent.');
            }
        });
    }
}
