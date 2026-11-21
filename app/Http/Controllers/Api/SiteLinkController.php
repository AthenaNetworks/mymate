<?php

namespace App\Http\Controllers\Api;

use App\Actions\History\GetInterfaceSamples;
use App\Http\Controllers\Controller;
use App\Http\Requests\SiteLink\UpdateSiteLinkRequest;
use App\Models\SiteLink;
use App\Support\SiteLinkLiveness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Site-to-site backhauls: binding an end / setting the intended role, and the two-direction
 * throughput series behind the geo map's hover and click drill-down (GitHub #85).
 *
 * Reads are open to any operator; the write is gated to admins by the global
 * RestrictWritesToAdmins middleware, like every other management route.
 */
class SiteLinkController extends Controller
{
    public function update(UpdateSiteLinkRequest $request, SiteLink $siteLink): JsonResponse
    {
        $data = $request->validated();

        // A human (or, later, an accepted discovery candidate) just confirmed this binding.
        // Imported pairs never claim verification on their own.
        if (array_key_exists('a_interface_id', $data) || array_key_exists('b_interface_id', $data)) {
            $data['verified_at'] = now();
            $data['binding_source'] ??= 'manual';
        }

        $siteLink->update($data);

        return response()->json(['data' => $this->payload($siteLink)]);
    }

    /**
     * GET /api/site-links/{siteLink}/throughput?from&to
     *
     * One series per direction, composited from the two bound ends' samples: A->B is what leaves
     * A (or arrives at B, whichever reports), B->A the reverse. Same bucketing/window rules as
     * the interface charts, so a link's history and an interface's history agree.
     */
    public function throughput(Request $request, SiteLink $siteLink, GetInterfaceSamples $samples): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])
            : $to->copy()->subSeconds((int) config('mymate.history.default_window', 3600));

        $siteLink->loadMissing(['aInterface', 'bInterface']);
        $a = $siteLink->aInterface;
        $b = $siteLink->bInterface;
        $capAb = $siteLink->effCapacityAbMbps();
        $capBa = $siteLink->effCapacityBaMbps();

        $aRows = $a !== null ? collect($samples($a->id, $from, $to))->keyBy('ts') : collect();
        $bRows = $b !== null ? collect($samples($b->id, $from, $to))->keyBy('ts') : collect();

        $series = $aRows->keys()
            ->merge($bRows->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $ts) use ($aRows, $bRows, $capAb, $capBa): array {
                $aRow = $aRows->get($ts);
                $bRow = $bRows->get($ts);

                $abBps = self::maxOrNull([$aRow['bps_out'] ?? null, $bRow['bps_in'] ?? null]);
                $baBps = self::maxOrNull([$bRow['bps_out'] ?? null, $aRow['bps_in'] ?? null]);

                return [
                    'ts' => $ts,
                    'bps_ab' => $abBps !== null ? (int) round($abBps) : null,
                    'bps_ba' => $baBps !== null ? (int) round($baBps) : null,
                    'util_ab' => self::utilPercent($abBps, $capAb),
                    'util_ba' => self::utilPercent($baBps, $capBa),
                ];
            })
            ->values();

        return response()->json([
            'data' => $series,
            'meta' => [
                'site_link_id' => $siteLink->id,
                'a_interface_id' => $a?->id,
                'b_interface_id' => $b?->id,
                'capacity_ab_mbps' => $capAb,
                'capacity_ba_mbps' => $capBa,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(SiteLink $link): array
    {
        $link->loadMissing(['siteA', 'siteB', 'aInterface', 'bInterface']);

        return [
            'id' => $link->id,
            'site_a_id' => $link->site_a_id,
            'site_b_id' => $link->site_b_id,
            'media_type' => $link->media_type,
            'role' => $link->role,
            'a_interface_id' => $link->a_interface_id,
            'b_interface_id' => $link->b_interface_id,
            'binding_source' => $link->binding_source,
            'binding_confidence' => $link->binding_confidence,
            'verified_at' => $link->verified_at?->toIso8601String(),
        ] + SiteLinkLiveness::evaluate($link);
    }

    private static function utilPercent(?float $bps, ?int $capacityMbps): ?float
    {
        if ($bps === null || $capacityMbps === null || $capacityMbps <= 0) {
            return null;
        }

        return round(($bps / ($capacityMbps * 1_000_000)) * 100, 3);
    }

    /** @param  array<int, mixed>  $values */
    private static function maxOrNull(array $values): int|float|null
    {
        $nums = array_filter($values, static fn ($v): bool => $v !== null);

        return $nums === [] ? null : max($nums);
    }
}
