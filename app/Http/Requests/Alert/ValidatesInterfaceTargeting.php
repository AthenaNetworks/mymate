<?php

namespace App\Http\Requests\Alert;

use App\Models\AlertPolicy;
use App\Support\InterfaceFilter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Rules for the interface targeting bits of an alert policy's params (GitHub #22 / #11),
 * shared by the store and update requests. See App\Support\InterfaceFilter for what each
 * mode means.
 */
trait ValidatesInterfaceTargeting
{
    /** @return array<string, mixed> */
    protected function interfaceTargetingRules(): array
    {
        return [
            // low_throughput only: watch links (default) or individual interfaces, eg a VLAN.
            'params.target' => ['nullable', Rule::in(['links', 'interfaces'])],
            'params.interfaces' => ['nullable', 'array'],
            'params.interfaces.mode' => ['nullable', Rule::in(InterfaceFilter::MODES)],
            'params.interfaces.match' => ['nullable', 'required_if:params.interfaces.mode,match', 'string', 'max:255'],
            'params.interfaces.interface_ids' => ['nullable', 'required_if:params.interfaces.mode,selected', 'array'],
            'params.interfaces.interface_ids.*' => ['integer', 'exists:interfaces,id'],
        ];
    }

    /**
     * A per-interface low_throughput policy on every port would page for every idle access
     * port in the fleet, so it has to be narrowed to linked / matched / picked interfaces.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $policy = $this->route('alert_policy');
                $condition = $this->input('condition', $policy instanceof AlertPolicy ? $policy->condition->value : null);
                // On update, params is replaced wholesale when sent, so only judge what was sent.
                if ($condition !== 'low_throughput' || ! $this->has('params')) {
                    return;
                }
                if ($this->input('params.target') !== 'interfaces') {
                    return;
                }
                if (in_array($this->input('params.interfaces.mode', 'all'), [null, 'all'], true)) {
                    $validator->errors()->add(
                        'params.interfaces.mode',
                        'Pick which interfaces to watch - a low throughput alert on every port would fire for every idle one.',
                    );
                }
            },
        ];
    }
}
