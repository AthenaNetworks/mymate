<?php

namespace App\Http\Requests\Command;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCommandRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $freeText = (bool) config('mymate.command.allow_free_text', false);

        return [
            'template_id' => [Rule::requiredIf(! $freeText), 'nullable', 'integer', 'exists:command_templates,id'],
            'template_updated_at' => ['required_with:template_id', 'date'],
            'command_hash' => ['required_with:template_id', 'string', 'size:64'],
            'command' => [Rule::requiredIf(fn () => $freeText && ! $this->filled('template_id')), 'nullable', 'string', 'max:4096'],
            'device_ids' => ['required', 'array', 'min:1', 'max:100'],
            'device_ids.*' => ['integer', 'distinct', 'exists:devices,id'],
            'timeout' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'confirm' => ['accepted'],
        ];
    }
}
