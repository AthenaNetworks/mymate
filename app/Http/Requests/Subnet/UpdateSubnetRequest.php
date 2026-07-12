<?php

namespace App\Http\Requests\Subnet;

use App\Services\Discovery\Scanner;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubnetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    public function rules(): array
    {
        return [
            'cidr' => [
                'sometimes', 'required', 'string', 'max:45',
                Rule::unique('subnets', 'cidr')->ignore($this->route('subnet')),
                $this->cidrRule(),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'scan_interval_s' => ['sometimes', 'integer', 'min:5', 'max:86400'],
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
