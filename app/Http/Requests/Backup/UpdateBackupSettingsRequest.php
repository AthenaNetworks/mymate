<?php

namespace App\Http\Requests\Backup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rusted backup-engine connection config. Admin-only (enforced by the
 * `RestrictWritesToAdmins` middleware on the route group). The token + SSH password are
 * write-only (blank/omitted = keep the stored one).
 *
 * The `api_url` is deliberately NOT run through SafeOutboundHost/OutboundHostGuard: unlike
 * an operator-supplied alert webhook, this is trusted admin-only infrastructure that points
 * at loopback by design - the SSRF guard would reject the correct default. It's validated as
 * a well-formed http(s) URL only.
 */
class UpdateBackupSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'api_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'timeout' => ['nullable', 'integer', 'min:5', 'max:600'],
            // Write-only secret - blank/omitted keeps the stored token.
            'api_token' => ['nullable', 'string', 'max:1024'],
            // Optional default SSH login used for devices whose own credential can't be reused.
            'ssh_username' => ['nullable', 'string', 'max:255'],
            'ssh_password' => ['nullable', 'string', 'max:1024'],
            'ssh_enable' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
