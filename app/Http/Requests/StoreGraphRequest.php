<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validate a custom graph (GitHub #28): a name plus a config of metric + series (+ total). */
class StoreGraphRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'config' => [$creating ? 'required' : 'sometimes', 'array'],
            'config.metric' => ['sometimes', Rule::in(['rate', 'util'])],
            'config.show_total' => ['sometimes', 'boolean'],
            'config.series' => ['sometimes', 'array', 'max:64'],
            'config.series.*.interface_id' => ['required', 'integer', 'exists:interfaces,id'],
            'config.series.*.direction' => ['required', Rule::in(['in', 'out'])],
        ];
    }
}
