<?php

namespace App\Http\Requests\Setting;

use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Bulk settings update. Array-of-pairs ({key,value}) to sidestep
 * dotted-key array validation; each key must be a known editable tunable and its
 * value within the SCHEMA range.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', Rule::in(array_keys(Settings::SCHEMA))],
            'settings.*.value' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            foreach ((array) $this->input('settings', []) as $i => $pair) {
                $key = $pair['key'] ?? null;
                $value = $pair['value'] ?? null;
                if (! is_string($key) || ! isset(Settings::SCHEMA[$key]) || ! is_numeric($value)) {
                    continue;
                }
                $meta = Settings::SCHEMA[$key];
                if ($value < $meta['min'] || $value > $meta['max']) {
                    $v->errors()->add("settings.$i.value", "{$meta['label']} must be between {$meta['min']} and {$meta['max']}.");
                }
            }
        });
    }
}
