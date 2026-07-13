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
            // Nullable: a ping-only device (no interfaces) links from the other end only.
            // When present, each interface must belong to its device end (also a DB trigger).
            'a_interface_id' => [
                'nullable', 'integer',
                Rule::exists('interfaces', 'id')->where('device_id', $this->input('a_device_id')),
            ],
            'b_interface_id' => [
                'nullable', 'integer',
                Rule::exists('interfaces', 'id')->where('device_id', $this->input('b_device_id')),
            ],
            // Per-direction bandwidth override. Nullable = "use the
            // derived speed (slowest end)"; 0 rejected (divide by zero).
            'bw_ab_mbps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'bw_ba_mbps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    /**
     * Reject an interface linked to itself, and a duplicate link between the same two
     * ends (device+interface, either direction). Null-aware so ping-only ends compare
     * correctly (SQL `= NULL` never matches, so a null end needs whereNull).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $aDev = $this->input('a_device_id');
            $bDev = $this->input('b_device_id');
            $a = $this->input('a_interface_id');
            $b = $this->input('b_interface_id');

            // A real interface can't be both ends of one link.
            if ($a !== null && $a === $b) {
                $validator->errors()->add('a_interface_id', "An interface can't link to itself.");

                return;
            }

            $duplicate = Link::query()
                ->when($this->ignoredLinkId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->where(function ($q) use ($aDev, $a, $bDev, $b): void {
                    // Same pair in either direction. Grouped so the id exclusion above
                    // applies to BOTH branches.
                    $q->where(fn ($qq) => $this->matchEnds($qq, $aDev, $a, $bDev, $b))
                        ->orWhere(fn ($qq) => $this->matchEnds($qq, $bDev, $b, $aDev, $a));
                })
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('a_interface_id', 'These devices are already linked on those interfaces.');
            }
        });
    }

    /** Constrain a query to a link whose A end is ($aDev,$aIf) and B end is ($bDev,$bIf), null-safe. */
    private function matchEnds($query, ?int $aDev, ?int $aIf, ?int $bDev, ?int $bIf): void
    {
        $query->where('a_device_id', $aDev)->where('b_device_id', $bDev);
        $aIf === null ? $query->whereNull('a_interface_id') : $query->where('a_interface_id', $aIf);
        $bIf === null ? $query->whereNull('b_interface_id') : $query->where('b_interface_id', $bIf);
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
