<?php

namespace App\Http\Requests;

use App\Enums\ImportMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validate a Dude-database upload: the .db file, the reconcile mode (fresh|upsert),
 * and whether to import chart history.
 */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum
    }

    public function rules(): array
    {
        $maxKb = (int) config('mymate.import.max_upload_kb', 524288);

        return [
            // SQLite has no reliable MIME; validate by size + extension, sniff magic in the controller.
            'database' => ['required', 'file', 'max:'.$maxKb],
            'mode' => ['required', new Enum(ImportMode::class)],
            'include_history' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'database.max' => 'The database file is larger than the configured upload limit.',
        ];
    }

    public function rulesNote(): void {}
}
