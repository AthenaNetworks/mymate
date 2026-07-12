<?php

namespace App\Http\Requests\Link;

use App\Models\Link;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only
    }

    public function rules(): array
    {
        return [
            'a_device_id' => ['required', 'integer', 'exists:devices,id'],
            'b_device_id' => ['required', 'integer', 'exists:devices,id'],
            // Each interface must belong to its device end (also enforced by a DB trigger).
            'a_interface_id' => [
                'required', 'integer', 'different:b_interface_id',
                Rule::exists('interfaces', 'id')->where('device_id', $this->input('a_device_id')),
            ],
            'b_interface_id' => [
                'required', 'integer',
                Rule::exists('interfaces', 'id')->where('device_id', $this->input('b_device_id')),
            ],
            // Per-direction bandwidth override. Nullable = "use the
            // derived speed (slowest end)"; 0 rejected (divide by zero).
            'bw_ab_mbps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'bw_ba_mbps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    /** Reject a duplicate link between the same two interfaces, in either direction. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $a = $this->input('a_interface_id');
            $b = $this->input('b_interface_id');
            if ($a === null || $b === null) {
                return;
            }

            $duplicate = Link::query()
                ->when($this->ignoredLinkId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->where(function ($q) use ($a, $b): void {
                    // Match the pair in either direction - grouped so the id exclusion
                    // above applies to BOTH branches (not just the first).
                    $q->where(fn ($qq) => $qq->where('a_interface_id', $a)->where('b_interface_id', $b))
                        ->orWhere(fn ($qq) => $qq->where('a_interface_id', $b)->where('b_interface_id', $a));
                })
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('a_interface_id', 'These interfaces are already linked.');
            }
        });
    }

    /**
     * The link id to exclude from the duplicate check. Null on create; the row
     * being edited on update (so re-saving an unchanged link isn't "a duplicate").
     */
    protected function ignoredLinkId(): ?int
    {
        return null;
    }
}
