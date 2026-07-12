<?php

namespace App\Http\Requests\Subnet;

use App\Services\Discovery\Scanner;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubnetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    /** Default the scan interval from config when the operator doesn't set one. */
    protected function prepareForValidation(): void
    {
        if (! $this->has('scan_interval_s') || $this->input('scan_interval_s') === null) {
            $this->merge([
                'scan_interval_s' => (int) config('mymate.discovery.default_scan_interval_s', 3600),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'cidr' => [
                'required', 'string', 'max:45',
                Rule::unique('subnets', 'cidr'),
                $this->cidrRule(),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'scan_interval_s' => ['integer', 'min:5', 'max:86400'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
        ];
    }

    /**
     * Reject anything that isn't a syntactically valid IPv4 CIDR, then reject
     * anything too broad or overlapping a range with no legitimate scan target.
     */
    protected function cidrRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Scanner::isValid($value)) {
                $fail('The :attribute must be a valid IPv4 CIDR (e.g. 10.0.0.0/24).');

                return;
            }
            if (! Scanner::isScannable($value)) {
                $fail('The :attribute must be no broader than a /8 and must not overlap loopback, link-local, or reserved ranges.');
            }
        };
    }
}
