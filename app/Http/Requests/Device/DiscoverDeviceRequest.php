<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * On-demand interface (re)discovery for one device. No body -
 * an authenticated operator triggering the same discover job the loop uses.
 */
class DiscoverDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
