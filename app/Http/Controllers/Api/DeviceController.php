<?php

namespace App\Http\Controllers\Api;

use App\Actions\Devices\CreateDevice;
use App\Actions\Devices\DeleteDevice;
use App\Actions\Devices\UpdateDevice;
use App\Actions\Devices\UpdateDevicePosition;
use App\Actions\Devices\UpgradePreflight;
use App\Actions\Devices\UseSnmpLocation;
use App\Actions\Upgrade\RecordUpgradeStatus;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDevicePositionRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Http\Requests\Device\UpgradeDevicesRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\DeviceSummaryResource;
use App\Jobs\BulkUpgradeJob;
use App\Jobs\UpgradeDeviceJob;
use App\Models\Device;
use App\Support\DeviceGeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 200;

    private const MAX_IDS = 500;

    private const SORTS = ['name', 'status', 'mgmt_ip', 'last_change', 'vendor', 'model'];

    /**
     * One page of the fleet (GitHub #22). At ~25k devices the old "everything at once" payload
     * ran php-fpm out of memory, so this is paged, searched and filtered server side and the SPA
     * only asks for what it draws. `fields=summary` returns the lean row the pickers use.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $sorts = [...self::SORTS, ...array_map(fn ($s) => "-{$s}", self::SORTS)];
        $v = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', Rule::enum(DeviceStatus::class)],
            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
            'poll_method' => ['sometimes', Rule::enum(PollMethod::class)],
            'monitored' => ['sometimes', 'boolean'],
            'placed' => ['sometimes', 'boolean'],
            'backup_enabled' => ['sometimes', 'boolean'],
            'upgrading' => ['sometimes', 'boolean'],
            'geo' => ['sometimes', Rule::in(['placed', 'unplaced'])],
            'map_id' => ['sometimes', 'integer'],
            'not_on_map' => ['sometimes', 'integer'],
            'parent_id' => ['sometimes', 'integer'],
            'not_under' => ['sometimes', 'integer'],
            'ids' => ['sometimes', 'array', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer'],
            'sort' => ['sometimes', Rule::in($sorts)],
            'fields' => ['sometimes', Rule::in(['full', 'summary'])],
        ]);

        $summary = ($v['fields'] ?? 'full') === 'summary';
        $query = Device::query()
            ->with($summary ? ['parent:id,name'] : ['parent:id,name', 'site'])
            ->withCount('mapPositions');
        $this->filter($query, $v);
        $this->sort($query, $v['sort'] ?? 'name');

        $page = $query->paginate((int) ($v['per_page'] ?? self::DEFAULT_PER_PAGE))->withQueryString();

        if ($summary) {
            return DeviceSummaryResource::collection($page);
        }

        // Effective geo coordinates for this page only. Ancestors that aren't on the page are
        // fetched slim so a CPE still inherits its tower's position.
        DeviceGeo::apply($page->getCollection(), loadAncestors: true);

        return DeviceResource::collection($page);
    }

    /**
     * Header tallies (GitHub #22): up/down/unknown across monitored devices plus how many are
     * paused. One GROUP BY instead of shipping the whole fleet to the browser to count it.
     * Visibility-scoped like every other device read.
     */
    public function stats(): JsonResponse
    {
        $rows = Device::query()
            ->selectRaw('status, monitored, COUNT(*) AS n')
            ->groupBy('status', 'monitored')
            ->toBase()
            ->get();

        $counts = ['up' => 0, 'down' => 0, 'unknown' => 0];
        $paused = 0;
        foreach ($rows as $row) {
            if (! Device::countsAsLive((bool) $row->monitored)) {
                $paused += (int) $row->n;

                continue;
            }
            $counts[$row->status] = ($counts[$row->status] ?? 0) + (int) $row->n;
        }

        return response()->json(['data' => $counts + [
            'paused' => $paused,
            'total' => array_sum($counts) + $paused,
        ]]);
    }

    /** @param  array<string, mixed>  $v */
    private function filter(Builder $query, array $v): void
    {
        if (($q = trim((string) ($v['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function (Builder $w) use ($like): void {
                foreach (['name', 'mgmt_ip', 'vendor', 'model'] as $col) {
                    $w->orWhere($col, 'ilike', $like);
                }
            });
        }

        foreach (['status', 'device_type', 'poll_method'] as $col) {
            if (isset($v[$col])) {
                $query->where($col, $v[$col]);
            }
        }
        foreach (['monitored', 'backup_enabled'] as $col) {
            if (isset($v[$col])) {
                $query->where($col, (bool) $v[$col]);
            }
        }

        if (isset($v['placed'])) {
            $v['placed'] ? $query->has('mapPositions') : $query->doesntHave('mapPositions');
        }
        if (isset($v['map_id'])) {
            $query->whereHas('mapPositions', fn (Builder $p) => $p->where('map_id', $v['map_id']));
        }
        if (isset($v['not_on_map'])) {
            $query->whereDoesntHave('mapPositions', fn (Builder $p) => $p->where('map_id', $v['not_on_map']));
        }
        if (! empty($v['upgrading'])) {
            $busy = array_filter(UpgradeStatus::cases(), fn (UpgradeStatus $s) => $s->inProgress());
            $query->whereIn('upgrade_status', array_map(fn (UpgradeStatus $s) => $s->value, $busy));
        }
        if (isset($v['parent_id'])) {
            $query->where('parent_device_id', $v['parent_id']);
        }
        if (isset($v['ids'])) {
            $query->whereIn('devices.id', array_map('intval', $v['ids']));
        }

        // Parent candidates for a device: drop it and everything below it so the picker can't
        // offer a loop (the NotADeviceDescendant rule, in SQL). UNION rather than UNION ALL
        // skips rows already seen, which also ends the walk if the data already has a loop.
        if (isset($v['not_under'])) {
            $query->whereRaw('devices.id NOT IN (
                WITH RECURSIVE below(id) AS (
                    SELECT CAST(? AS bigint)
                    UNION
                    SELECT d.id FROM devices d JOIN below ON d.parent_device_id = below.id
                )
                SELECT id FROM below)', [(int) $v['not_under']]);
        }

        // Would DeviceGeo find coordinates for it? It does when the device, or anything up its
        // uplink chain, has its own pin or a placed site (an "anchor"). Both sides are walked
        // downward so each device is visited once, and both are a plain IN: Postgres can't
        // size a recursive CTE, and a NOT IN over one fell back to a row-by-row scan that took
        // seconds at 25k devices.
        if (isset($v['geo'])) {
            $anchored = '((d.latitude IS NOT NULL AND d.longitude IS NOT NULL) OR (s.latitude IS NOT NULL AND s.longitude IS NOT NULL))';
            $query->whereRaw($v['geo'] === 'placed'
                // Anchors and everything below them.
                ? "devices.id IN (
                    WITH RECURSIVE placed(id) AS (
                        SELECT d.id FROM devices d LEFT JOIN sites s ON s.id = d.site_id WHERE {$anchored}
                        UNION
                        SELECT c.id FROM devices c JOIN placed ON c.parent_device_id = placed.id
                    )
                    SELECT id FROM placed)"
                // Un-anchored roots, and un-anchored devices below them (stopping at any anchor).
                : "devices.id IN (
                    WITH RECURSIVE lost(id) AS (
                        SELECT d.id FROM devices d LEFT JOIN sites s ON s.id = d.site_id
                        WHERE d.parent_device_id IS NULL AND NOT {$anchored}
                        UNION
                        SELECT d.id FROM devices d JOIN lost ON d.parent_device_id = lost.id
                        LEFT JOIN sites s ON s.id = d.site_id WHERE NOT {$anchored}
                    )
                    SELECT id FROM lost)");
        }
    }

    private function sort(Builder $query, string $sort): void
    {
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-'); // whitelisted by the validator

        if ($col === 'status') {
            // Worst first: down, then unknown, then up.
            $query->orderByRaw("CASE devices.status WHEN 'down' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END {$dir}");
        } elseif ($col !== 'name') {
            $query->orderByRaw("devices.{$col} {$dir} NULLS LAST");
        }

        // Name then id as the tie-break so pages don't shuffle between requests.
        $query->orderBy('devices.name', $col === 'name' ? $dir : 'asc')->orderBy('devices.id');
    }

    public function store(StoreDeviceRequest $request, CreateDevice $createDevice): JsonResponse
    {
        $device = $createDevice($request->validated());

        return (new DeviceResource($device->loadMissing('parent')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Device $device): DeviceResource
    {
        $device->loadMissing('parent', 'site')->loadCount('mapPositions');
        DeviceGeo::apply([$device], loadAncestors: true);

        return new DeviceResource($device);
    }

    public function update(UpdateDeviceRequest $request, Device $device, UpdateDevice $updateDevice): DeviceResource
    {
        $device = $updateDevice($device, $request->validated())->loadMissing('parent', 'site');
        DeviceGeo::apply([$device], loadAncestors: true);

        return new DeviceResource($device);
    }

    /**
     * Drop a manual pin and put the device back on its SNMP / RouterOS location (GitHub #22).
     * `moved` says whether it moved now or waits for the next capture to place it.
     */
    public function useSnmpLocation(Device $device, UseSnmpLocation $useSnmpLocation): JsonResponse
    {
        $moved = $useSnmpLocation($device);
        $device->loadMissing('parent', 'site');
        DeviceGeo::apply([$device], loadAncestors: true);

        return (new DeviceResource($device))->additional(['meta' => ['moved' => $moved]])->response();
    }

    public function updatePosition(UpdateDevicePositionRequest $request, Device $device, UpdateDevicePosition $updatePosition): DeviceResource
    {
        $data = $request->validated();

        return new DeviceResource($updatePosition($device, (float) $data['map_x'], (float) $data['map_y']));
    }

    public function destroy(Device $device, DeleteDevice $deleteDevice): Response
    {
        $deleteDevice($device);

        return response()->noContent();
    }

    /**
     * Take the device off every map at once (the Devices list's bulk "Remove from maps").
     * The device itself, its links and its history are untouched - it stays monitored and
     * can be re-added from any map's inspector.
     */
    public function unplace(Device $device): Response
    {
        $device->mapPositions()->delete();

        return response()->noContent();
    }

    /**
     * Dry-run the dependency checks: return the downstream-first
     * order and, per device, whether it would upgrade or be skipped (and why) -
     * without touching anything. The UI shows this before the operator confirms.
     */
    public function upgradePreflight(UpgradeDevicesRequest $request, UpgradePreflight $preflight): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['device_ids']);

        return response()->json($preflight($ids, (bool) ($data['preserve_order'] ?? false), $data['version'] ?? null));
    }

    /**
     * Queue a firmware upgrade for the given devices. `ordered` runs one
     * BulkUpgradeJob that walks them downstream-first (waiting for each to recover
     * before its parent); otherwise one isolated job per device, in parallel.
     */
    public function upgrade(UpgradeDevicesRequest $request, RecordUpgradeStatus $record): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['device_ids']);

        $version = $data['version'] ?? null;

        // Mark queued up front so the UI shows a spinner immediately (before a worker picks it up).
        // This opens the history row too, which is where who asked and the batch get recorded.
        // Only devices the caller can see (the visibility scope applies), kept in the order given.
        $devices = Device::whereIn('id', $ids)->get()->keyBy('id');
        $ids = array_values(array_filter($ids, fn (int $id) => $devices->has($id)));
        $batchId = count($ids) > 1 ? (string) Str::uuid() : null;
        foreach ($ids as $id) {
            $record($devices[$id], UpgradeStatus::Queued, 'Queued for upgrade...', $version, $request->user()?->id, $batchId);
        }

        $source = $data['source'] ?? 'mikrotik';

        if ($data['ordered'] ?? false) {
            BulkUpgradeJob::dispatch($ids, (bool) ($data['explicit_order'] ?? false), $version, $source);
        } else {
            foreach ($ids as $id) {
                UpgradeDeviceJob::dispatch($id, $version, $source);
            }
        }

        return response()->json([
            'queued' => count($ids),
            'ordered' => (bool) ($data['ordered'] ?? false),
        ], Response::HTTP_ACCEPTED);
    }
}
