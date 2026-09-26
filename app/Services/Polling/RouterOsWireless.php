<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\RouterOs\RouterOsConnection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Wireless RF over the RouterOS API, for every MikroTik wireless stack:
 *
 *   wifi       /interface/wifi/registration-table        RouterOS 7.13+ (wifi-qcom / wifi-qcom-ac)
 *   wifiwave2  /interface/wifiwave2/registration-table   RouterOS 7.12 and older, same menu renamed
 *   wireless   /interface/wireless/registration-table    the legacy wireless package (v6 and v7)
 *   capsman    /caps-man/registration-table              legacy CAPsMAN controller (wireless package)
 *
 * Which menus a board has is probed once and cached per device + os_version (see stacks()), so a
 * normal poll only asks for the tables that exist. More than one can exist at once: from 7.13 the
 * wifi CAPsMAN controller ships in the base routeros package, so every 7.13+ router has an (empty)
 * /interface/wifi menu, and a hAP ac2 on 7.x with legacy radios has that plus /interface/wireless.
 * That's why we don't stop at the first menu that answers - we read every one that exists and
 * merge the rows. wifi and wifiwave2 never both exist (it's a rename), so wifiwave2 is only tried
 * when wifi isn't there, and caps-man only when the legacy wireless menu is.
 *
 * CAPsMAN: on a controller the registration table lists the stations of every CAP it manages
 * (wifi CAPsMAN puts the remote cap interfaces in the same /interface/wifi table, legacy CAPsMAN
 * has its own /caps-man table). We attribute all of them to the controller - it's the table the
 * controller actually has, and splitting per CAP would need a cap interface -> device mapping we
 * can't do reliably. Rows are de-duplicated by MAC across the merged tables so one station is
 * counted once even if it shows up in two of them mid roam.
 *
 * No rows at all -> everything null (not 0 clients), same as before. Every 7.13+ router has the
 * empty wifi table, reporting 0 there would make every wired router look like an AP.
 */
class RouterOsWireless
{
    /** Registration table menus, keyed by our stack name, in probe order. */
    public const MENUS = [
        'wifi' => '/interface/wifi/registration-table/print',
        'wifiwave2' => '/interface/wifiwave2/registration-table/print',
        'wireless' => '/interface/wireless/registration-table/print',
        'capsman' => '/caps-man/registration-table/print',
    ];

    /** How long a probe result is trusted. os_version is in the key, so an upgrade re-probes anyway. */
    private const CACHE_TTL = 6 * 3600;

    /**
     * Read and summarise RF for a device over an open connection. Never throws - a missing menu or
     * a failed query just means less (or no) RF this poll.
     *
     * @return array{signal:?float, snr:?float, ccq:?float, clients:?int}
     */
    public static function read(RouterOsConnection $conn, Device $device): array
    {
        $key = self::cacheKey($device);
        $cached = Cache::get($key);
        $rowsByStack = [];

        if (is_array($cached)) {
            foreach ($cached as $stack) {
                if (! isset(self::MENUS[$stack])) {
                    continue;
                }
                [$state, $rows] = self::query($conn, self::MENUS[$stack]);
                if ($state === 'missing') {
                    // The package went away (or got swapped) without a version change - probe
                    // again next poll rather than trapping on it forever.
                    Cache::forget($key);
                } elseif ($state === 'ok') {
                    $rowsByStack[$stack] = $rows;
                }
            }

            return self::summarise($rowsByStack);
        }

        $present = [];
        $complete = true;
        $try = static function (string $stack) use ($conn, &$present, &$complete, &$rowsByStack): bool {
            [$state, $rows] = self::query($conn, self::MENUS[$stack]);
            if ($state === 'ok') {
                $present[] = $stack;
                $rowsByStack[$stack] = $rows;

                return true;
            }
            if ($state === 'error') {
                $complete = false; // timeout or similar - don't cache a half answer
            }

            return false;
        };

        if (! $try('wifi')) {
            $try('wifiwave2');
        }
        if ($try('wireless')) {
            $try('capsman');
        }

        if ($complete) {
            Cache::put($key, $present, self::CACHE_TTL);
        }

        return self::summarise($rowsByStack);
    }

    public static function cacheKey(Device $device): string
    {
        return 'routeros:wl-stacks:'.$device->id.':'.($device->os_version ?? '');
    }

    /**
     * One registration table print. 'missing' = the menu doesn't exist on this board ("no such
     * command" / "no such command prefix"), 'error' = anything else went wrong.
     *
     * The PHP API lib doesn't throw on a !trap, it hands the trap's =message= back as if it were a
     * row, so a lone row carrying just a message is a trap too.
     *
     * @return array{0: 'ok'|'missing'|'error', 1: list<array<string, string>>}
     */
    private static function query(RouterOsConnection $conn, string $command): array
    {
        try {
            $rows = $conn->query($command);
        } catch (Throwable $e) {
            return [self::isMissingMenu($e->getMessage()) ? 'missing' : 'error', []];
        }

        if (count($rows) === 1 && isset($rows[0]['message']) && ! isset($rows[0]['mac-address'])) {
            return [self::isMissingMenu((string) $rows[0]['message']) ? 'missing' : 'error', []];
        }

        return ['ok', array_values($rows)];
    }

    private static function isMissingMenu(string $message): bool
    {
        return str_contains(strtolower($message), 'no such command');
    }

    /**
     * Rows from each stack -> the device's RF. Clients is the (MAC de-duplicated) row count,
     * signal / SNR / CCQ the average across the rows that have them.
     *
     * Field names per stack:
     *   wireless   signal-strength ("-65dBm@6Mbps"), signal-to-noise, tx-ccq
     *   wifi(wave2) signal (dBm, documented). No SNR and no CCQ in the documented table - 11ax has
     *              no CCQ, so it stays null rather than being made up. We still look at
     *              signal-strength / rx-signal and the per-chain signal-strength-chN / signal-chN
     *              in case a version names it differently (not documented, just defensive), and
     *              signal-to-noise if a later version ever adds it.
     *   capsman    rx-signal (dBm). No SNR, tx-ccq only if present.
     *
     * @param  array<string, list<array<string, string>>>  $rowsByStack
     * @return array{signal:?float, snr:?float, ccq:?float, clients:?int}
     */
    public static function summarise(array $rowsByStack): array
    {
        $signals = $snrs = $ccqs = [];
        $seen = [];
        $clients = 0;

        foreach ($rowsByStack as $stack => $rows) {
            foreach ($rows as $row) {
                $mac = strtoupper(trim((string) ($row['mac-address'] ?? '')));
                if ($mac !== '') {
                    if (isset($seen[$mac])) {
                        continue;
                    }
                    $seen[$mac] = true;
                }
                $clients++;

                $signal = match ($stack) {
                    'wireless' => self::firstNumber($row['signal-strength'] ?? null),
                    'capsman' => self::firstNumber($row['rx-signal'] ?? $row['signal-strength'] ?? null),
                    default => self::wifiSignal($row),
                };
                if ($signal !== null) {
                    $signals[] = $signal;
                }
                $snr = self::firstNumber($row['signal-to-noise'] ?? null);
                if ($snr !== null) {
                    $snrs[] = $snr;
                }
                // CCQ only exists on the legacy driver; wifi has none.
                if ($stack === 'wireless' || $stack === 'capsman') {
                    $ccq = self::firstNumber($row['tx-ccq'] ?? null);
                    if ($ccq !== null) {
                        $ccqs[] = $ccq;
                    }
                }
            }
        }

        if ($clients === 0) {
            return ['signal' => null, 'snr' => null, 'ccq' => null, 'clients' => null];
        }

        $avg = static fn (array $v): ?float => $v === [] ? null : round(array_sum($v) / count($v), 1);

        return [
            'signal' => $avg($signals),
            'snr' => $avg($snrs),
            'ccq' => $avg($ccqs),
            'clients' => $clients,
        ];
    }

    /**
     * A wifi / wifiwave2 row's signal. `signal` is the documented one. When only per-chain values
     * are there we take the strongest chain, which is closer to the combined figure than the mean
     * of the chains would be.
     *
     * @param  array<string, string>  $row
     */
    private static function wifiSignal(array $row): ?float
    {
        foreach (['signal', 'signal-strength', 'rx-signal'] as $field) {
            $v = self::firstNumber($row[$field] ?? null);
            if ($v !== null) {
                return $v;
            }
        }

        $chains = [];
        foreach ($row as $field => $value) {
            if (preg_match('/^(signal|signal-strength|rx-signal)-ch\d+$/', (string) $field) === 1) {
                $v = self::firstNumber($value);
                if ($v !== null) {
                    $chains[] = $v;
                }
            }
        }

        return $chains === [] ? null : max($chains);
    }

    /** First signed/decimal number in a value (e.g. "-65dBm@6Mbps" -> -65.0), or null. */
    private static function firstNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1 ? (float) $m[0] : null;
    }
}
