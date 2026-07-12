<?php

namespace App\Http\Requests\Setting;

use App\Rules\SafeOutboundHost;
use App\Support\MailSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Operator SMTP mail-server config. Password is write-only (blank = keep). */
class UpdateMailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // SafeOutboundHost blocks loopback/link-local/reserved
            // targets but allows RFC1918 - an internal SMTP relay is a legitimate target.
            'host' => ['required', 'string', 'max:255', new SafeOutboundHost],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(MailSettings::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            // Blank/omitted keeps the stored password; a value replaces it (encrypted on save).
            'password' => ['nullable', 'string', 'max:1024'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
