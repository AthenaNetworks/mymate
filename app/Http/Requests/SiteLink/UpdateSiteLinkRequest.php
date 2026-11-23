<?php

namespace App\Http\Requests\SiteLink;

use App\Models\SiteLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Re-bind one end of a site-to-site backhaul, or set its intended role/capacity (GitHub #85).
 *
 * The site pair itself belongs to the OSS import and is not editable here. A bound interface is
 * checked twice beyond the FK: it must belong to gear at that end's site, and it may not already
 * be the end of another backhaul. The database enforces both as well (trigger + partial unique
 * indexes); this layer exists so the API answers 422 with a useful message instead of a 500.
 */
class UpdateSiteLinkRequest extends FormRequest
{
    /** Writes are gated to admins by the global RestrictWritesToAdmins middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'a_interface_id' => ['sometimes', 'nullable', 'integer', 'exists:interfaces,id'],
            'b_interface_id' => ['sometimes', 'nullable', 'integer', 'exists:interfaces,id'],
            'role' => ['sometimes', Rule::in(SiteLink::ROLES)],
            'media_type' => ['sometimes', 'nullable', Rule::in(['fiber', 'wireless', 'other'])],
            'capacity_ab_mbps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'capacity_ba_mbps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'binding_source' => ['sometimes', Rule::in(SiteLink::BINDING_SOURCES)],
            'binding_confidence' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $link = $this->route('siteLink');
            if (! $link instanceof SiteLink) {
                return;
            }

            foreach ([['a_interface_id', 'site_a_id'], ['b_interface_id', 'site_b_id']] as [$field, $siteField]) {
                if (! $this->has($field) || $this->input($field) === null) {
                    continue;
                }

                $interfaceId = (int) $this->input($field);
                $siteId = (int) $link->{$siteField};

                $belongs = DB::table('interfaces')
                    ->join('devices', 'devices.id', '=', 'interfaces.device_id')
                    ->where('interfaces.id', $interfaceId)
                    ->value('devices.site_id');

                if ((int) $belongs !== $siteId) {
                    $validator->errors()->add($field, 'That interface is not at this end\'s site.');

                    continue;
                }

                $taken = SiteLink::query()
                    ->whereKeyNot($link->getKey())
                    ->where(fn ($q) => $q->where('a_interface_id', $interfaceId)->orWhere('b_interface_id', $interfaceId))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add($field, 'That interface is already bound to another site link.');
                }
            }
        });
    }
}
