<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScopeRules;
use App\Models\Device;
use App\Support\DeviceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/scopes/preview - how many devices a targeting scope (alert policy, maintenance
 * window, custom sensor) would cover, and a few of their names, for the scope editor's "Matches N
 * devices" line. Combined rules with exclusions are hard to judge by eye, and a wrong exclusion
 * silently mutes alerts, so the editor shows what a scope means before it's saved.
 *
 * Nothing is saved. A POST in the operator group, so it's admin-only like the editors that use it
 * (RestrictWritesToAdmins), and restricted operators can't reach it (RestrictedAccess).
 */
class ScopePreviewController extends Controller
{
    private const SAMPLE = 8;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(ScopeRules::rules());
        $scope = DeviceScope::normalize($data['scope'] ?? null);
        $ids = DeviceScope::resolve($scope);

        $total = Device::withoutGlobalScopes()->count();
        $query = Device::withoutGlobalScopes();
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return response()->json(['data' => [
            'all' => $ids === null,
            'count' => $ids === null ? $total : count($ids),
            'total' => $total,
            'sample' => $query->orderBy('name')->limit(self::SAMPLE)->get(['id', 'name'])
                ->map(fn (Device $d) => ['id' => $d->id, 'name' => $d->name])->all(),
        ]]);
    }
}
