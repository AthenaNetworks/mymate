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
            // Per-graph style override. Absent = inherit the house default (App\Support\GraphSettings).
            'config.style' => ['sometimes', 'nullable', 'array'],
            'config.style.fill' => ['sometimes', 'boolean'],
            'config.style.stacked' => ['sometimes', 'boolean'],
            'config.style.color_mode' => ['sometimes', 'in:group,series'],
            'config.series' => ['sometimes', 'array', 'max:64'],
            // A series is sourced from an interface (default), a custom sensor, ping latency, or a
            // service probe. Only the ids for its source are used; all are existence-checked.
            'config.series.*.source' => ['sometimes', Rule::in(['interface', 'sensor', 'ping', 'probe'])],
            'config.series.*.interface_id' => ['sometimes', 'integer', 'exists:interfaces,id'],
            'config.series.*.direction' => ['sometimes', Rule::in(['in', 'out'])],
            'config.series.*.sensor_id' => ['sometimes', 'integer', 'exists:sensors,id'],
            'config.series.*.device_id' => ['sometimes', 'integer', 'exists:devices,id'],
            'config.series.*.probe_id' => ['sometimes', 'integer', 'exists:probes,id'],
            // A display label the editor captured when the series was added (the chart computes its
            // own authoritative label; this is just for editing convenience).
            'config.series.*.label' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Optional custom series name shown on the chart (overrides the computed label).
            'config.series.*.name' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Optional per-series line/fill colour; null/absent = the categorical palette default.
            'config.series.*.color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
