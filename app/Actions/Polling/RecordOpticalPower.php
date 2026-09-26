<?php

namespace App\Actions\Polling;

use App\Services\Polling\OpticalReading;
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
        }

        DB::table('interfaces')
            ->where('device_id', $deviceId)
            ->whereNotIn('id', array_keys($seen))
            ->where(fn ($q) => $q->whereNotNull('optical_rx_dbm')->orWhereNotNull('optical_tx_dbm'))
            ->update(['optical_rx_dbm' => null, 'optical_tx_dbm' => null, 'optical_at' => null]);

        return count($seen);
    }
}
