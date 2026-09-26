<?php

namespace App\Actions\Polling;

use App\Services\Polling\OpticalReading;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;

/**
 * Write one device's optical power readings (GitHub #11) onto its interface rows. Shared by the
 * central metrics tick and agent ingest so both match ports the same way: by name when the
 * reading carries one, else by ifIndex. A reading that matches no known interface is dropped
 * (discovery hasn't created it yet).
 *
 * This is only called after a SUCCESSFUL read, so any port on the device that still has optical
 * values but wasn't in this read has lost its module (or light reading) and is cleared - a pulled
 * SFP shouldn't keep showing, or alerting on, its last level forever.
 *
 * Each matched reading also goes into optical_samples (the `optical` history family).
 *
 * Returns the number of interfaces updated.
 */
class RecordOpticalPower
{
    /** @param  list<OpticalReading>  $readings */
    public function __invoke(int $deviceId, array $readings): int
    {
        $ifaces = DB::table('interfaces')->where('device_id', $deviceId)->get(['id', 'if_index', 'name']);
        $byName = [];
        $byIndex = [];
        foreach ($ifaces as $i) {
            $byName[(string) $i->name] = (int) $i->id;
            $byIndex[(int) $i->if_index] = (int) $i->id;
        }

        $now = now();
        $seen = [];
        $samples = [];
        foreach ($readings as $r) {
            $id = ($r->name !== null ? ($byName[$r->name] ?? null) : null)
                ?? ($r->ifIndex !== null ? ($byIndex[$r->ifIndex] ?? null) : null);
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            DB::table('interfaces')->where('id', $id)->update([
                'optical_rx_dbm' => $r->rxDbm,
                'optical_tx_dbm' => $r->txDbm,
                'optical_at' => $now,
            ]);
            $samples[] = ['interface_id' => $id, 'ts' => $now->format('Y-m-d H:i:s'), 'rx_dbm' => $r->rxDbm, 'tx_dbm' => $r->txDbm];
        }

        // History for the `optical` family, best-effort like every other sample write.
        if ($samples !== [] && config('mymate.history.enabled', true)) {
            try {
                // own transaction, so a failed insert inside an outer one can't poison it
                DB::transaction(fn () => DB::table('optical_samples')->insert($samples));
            } catch (\Throwable $e) {
                EngineLog::warning('history: optical sample write failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);
            }
        }

        DB::table('interfaces')
            ->where('device_id', $deviceId)
            ->whereNotIn('id', array_keys($seen))
            ->where(fn ($q) => $q->whereNotNull('optical_rx_dbm')->orWhereNotNull('optical_tx_dbm'))
            // Stamped rather than nulled, so the next live frame sees it as news and tells open
            // views the module is gone. Alerts only look at non-null readings, so this can't fire.
            ->update(['optical_rx_dbm' => null, 'optical_tx_dbm' => null, 'optical_at' => $now]);

        return count($seen);
    }
}
