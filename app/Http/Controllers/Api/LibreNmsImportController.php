<?php

namespace App\Http\Controllers\Api;

use App\Actions\Import\ImportFromLibreNms;
use App\Http\Controllers\Controller;
use App\Http\Requests\LibreNmsImportRequest;
use App\Services\Import\LibreNms\LibreNmsApiSource;
use App\Services\Import\LibreNms\LibreNmsMysqlSource;
use App\Services\Import\LibreNms\LibreNmsSource;
use App\Support\EngineLog;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Import from LibreNMS: `preview` dry-runs the connection and returns the devices (and maps,
 * for MySQL) found; `import` creates them. Connection/query failures come back as 422 with a
 * readable message so the operator can fix credentials/URL.
 */
class LibreNmsImportController extends Controller
{
    public function preview(LibreNmsImportRequest $request): JsonResponse
    {
        try {
            $source = $this->source($request);
            $devices = $source->devices();
            $maps = array_map(
                static fn (array $m): array => ['name' => $m['name'], 'node_count' => count($m['nodes'])],
                $source->maps(),
            );
        } catch (Throwable $e) {
            return $this->error($e);
        }

        return response()->json([
            'data' => [
                'driver' => $request->validated('driver'),
                'device_count' => count($devices),
                'with_community' => count(array_filter($devices, fn ($d) => ! empty($d['snmp_community']))),
                'devices' => $devices,
                'maps' => $maps,
            ],
        ]);
    }

    public function import(LibreNmsImportRequest $request, ImportFromLibreNms $import): JsonResponse
    {
        $data = $request->validated();

        try {
            $summary = $import($this->source($request), [
                'credential_id' => $data['credential_id'] ?? null,
                'credential_map' => $data['credential_map'] ?? [],
                'import_credentials' => $data['import_credentials'] ?? false,
                'import_maps' => $data['import_maps'] ?? false,
                'include_disabled' => $data['include_disabled'] ?? false,
                'only' => $data['only'] ?? null,
            ]);
        } catch (Throwable $e) {
            return $this->error($e);
        }

        return response()->json(['data' => $summary]);
    }

    private function source(LibreNmsImportRequest $request): LibreNmsSource
    {
        $d = $request->validated();

        return $d['driver'] === 'api'
            ? new LibreNmsApiSource($d['base_url'], $d['token'])
            : new LibreNmsMysqlSource($d['host'], (int) ($d['port'] ?? 3306), $d['database'], $d['username'], $d['password'] ?? '');
    }

    private function error(Throwable $e): JsonResponse
    {
        EngineLog::warning('librenms import failed', ['error' => $e->getMessage()]);

        return response()->json(['message' => 'LibreNMS: '.$e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
