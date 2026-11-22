<?php

namespace App\Actions\Links;

use App\Models\Device;
use App\Models\Link;
use App\Models\LinkSuggestion;
use App\Models\NetworkInterface;
use App\Services\Neighbors\Neighbor;
use App\Services\Neighbors\NeighborTables;
use Illuminate\Support\Collection;

/**
 * Turn one device's neighbour tables into link suggestions (GitHub #85). Never draws a link: a
 * suggestion waits in the review queue for an operator.
 *
 * Matching, from the issue's field notes:
 *  - Our port: by name first (lldpLocPortId / Desc against ifName, ifAlias), because LLDP's local
 *    port number is not always the ifIndex (Comware). The number is only a last resort. CDP gives
 *    the ifIndex outright.
 *  - The far device: by its LLDP chassis id, then its management address, then its name (whole,
 *    or the first label of an FQDN). The LLDP address is often on another VLAN than the one My
 *    Mate polls, so the chassis id and name matter. An ambiguous name matches nothing.
 *  - The far port: by name, description, a bare number as an ifIndex, or a Cisco-style short name
 *    ("Gi1/0/1" for "GigabitEthernet1/0/1"). No match still suggests the link, with that end open,
 *    like a ping-only device.
 *
 * A neighbour that isn't a My Mate device, or a cable an existing link already covers, is skipped.
 * Each LAG member is its own suggestion, since each is its own cable.
 */
class RecordNeighbors
{
    /** A suggestion nobody has reported for this long has gone away - drop it. */
    public const STALE_DAYS = 3;

    /** @var array<int, Collection<int, NetworkInterface>> */
    private array $interfaces = [];

    /** @return int how many suggestions were added or refreshed */
    public function __invoke(Device $device, NeighborTables $tables): int
    {
        $chassis = $tables->localChassisId();
        if ($chassis !== null && $chassis !== $device->lldp_chassis_id) {
            $device->forceFill(['lldp_chassis_id' => $chassis])->save();
        }

        $n = 0;
        foreach ($tables->parse() as $neighbor) {
            $n += $this->record($device, $neighbor) ? 1 : 0;
        }

        LinkSuggestion::where('status', 'new')->where('last_seen_at', '<', now()->subDays(self::STALE_DAYS))->delete();

        return $n;
    }

    private function record(Device $device, Neighbor $nb): bool
    {
        $remote = $this->remoteDevice($nb);
        if ($remote === null || $remote->id === $device->id) {
            return false;
        }

        $local = $this->localInterface($device->id, $nb);
        $far = $this->port($this->interfacesOf($remote->id), $nb->remotePorts);

        // The lower device id is always the `a` end, so both ends of a cable agree on the key.
        [$a, $aIf, $b, $bIf] = $device->id < $remote->id
            ? [$device->id, $local, $remote->id, $far]
            : [$remote->id, $far, $device->id, $local];

        if ($this->covered($a, $aIf, $b, $bIf)) {
            LinkSuggestion::where('a_device_id', $a)->where('b_device_id', $b)->get()
                ->filter(fn (LinkSuggestion $s) => $this->covered($a, $s->a_interface_id, $b, $s->b_interface_id))
                ->each->delete();

            return false;
        }

        $siblings = LinkSuggestion::where('a_device_id', $a)->where('b_device_id', $b)->get();
        $fits = fn (?int $known, ?int $ours) => $ours === null || $known === $ours;

        // Something at least as complete is already queued (the other end told us more).
        $existing = $siblings->first(fn (LinkSuggestion $s) => $fits($s->a_interface_id, $aIf) && $fits($s->b_interface_id, $bIf));
        if ($existing !== null) {
            $existing->update(['last_seen_at' => now()]);

            return true;
        }

        // This one knows more than a queued partial: replace it, keeping an "ignored".
        $partials = $siblings->filter(fn (LinkSuggestion $s) => $fits($aIf, $s->a_interface_id) && $fits($bIf, $s->b_interface_id));
        $ignored = $partials->contains(fn (LinkSuggestion $s) => $s->status === 'ignored');
        $first = $partials->min('first_seen_at') ?? now();
        $partials->each->delete();

        LinkSuggestion::create([
            'a_device_id' => $a, 'a_interface_id' => $aIf, 'b_device_id' => $b, 'b_interface_id' => $bIf,
            'pair_key' => LinkSuggestion::key($a, $aIf, $b, $bIf),
            'protocol' => $nb->protocol,
            'status' => $ignored ? 'ignored' : 'new',
            'first_seen_at' => $first,
            'last_seen_at' => now(),
        ]);

        return true;
    }

    /**
     * An existing link between the two devices already accounts for this cable: on each end its
     * interface is the same, or one of them isn't known.
     */
    public function covered(int $a, ?int $aIf, int $b, ?int $bIf): bool
    {
        $fits = fn (?int $x, ?int $y) => $x === null || $y === null || $x === $y;

        return Link::withoutGlobalScopes()
            ->where(fn ($q) => $q->where(['a_device_id' => $a, 'b_device_id' => $b])->orWhere(['a_device_id' => $b, 'b_device_id' => $a]))
            ->get(['a_device_id', 'a_interface_id', 'b_device_id', 'b_interface_id'])
            ->contains(function (Link $l) use ($a, $aIf, $bIf, $fits) {
                [$lA, $lB] = $l->a_device_id === $a ? [$l->a_interface_id, $l->b_interface_id] : [$l->b_interface_id, $l->a_interface_id];

                return $fits($lA, $aIf) && $fits($lB, $bIf);
            });
    }

    /** Looked up per neighbour, so a big fleet isn't loaded for every discovery pass. */
    private function remoteDevice(Neighbor $nb): ?Device
    {
        $one = function (callable $where): ?Device {
            $hit = Device::withoutGlobalScopes()->where($where)->limit(2)->get(['id']);

            return $hit->count() === 1 ? $hit->first() : null;
        };

        if ($nb->remoteChassis !== null && ($d = $one(fn ($q) => $q->where('lldp_chassis_id', $nb->remoteChassis)))) {
            return $d;
        }
        if ($nb->remoteIp !== null && ($d = $one(fn ($q) => $q->where('mgmt_ip', $nb->remoteIp)))) {
            return $d;
        }
        if ($nb->remoteName === null) {
            return null;
        }

        // The whole name, then the first label of an FQDN ("core1.example.net" -> "core1"). More
        // than one device answering to it is ambiguous, so it matches nothing.
        $want = strtolower(trim($nb->remoteName));
        foreach (array_unique([$want, self::firstLabel($want)]) as $name) {
            $hit = Device::withoutGlobalScopes()
                ->where(fn ($q) => $q->whereRaw('lower(name) = ?', [$name])->orWhereRaw('lower(alias) = ?', [$name])
                    ->orWhereRaw('lower(name) like ?', [addcslashes($name, '%_\\').'.%']))
                ->limit(2)->get(['id']);
            if ($hit->count() === 1) {
                return $hit->first();
            }
            if ($hit->count() > 1) {
                return null;
            }
        }

        return null;
    }

    private function localInterface(int $deviceId, Neighbor $nb): ?int
    {
        $ifaces = $this->interfacesOf($deviceId);
        if ($nb->localIfIndex !== null) {
            return $ifaces->firstWhere('if_index', $nb->localIfIndex)?->id;
        }
        $byName = $this->port($ifaces, $nb->localPorts);
        if ($byName !== null || $nb->localPorts !== []) {
            return $byName;
        }

        // LLDP gave a port number and nothing else to go on.
        return $nb->localPortNum !== null ? $ifaces->firstWhere('if_index', $nb->localPortNum)?->id : null;
    }

    /**
     * @param  Collection<int, NetworkInterface>  $ifaces
     * @param  list<string>  $names
     */
    private function port(Collection $ifaces, array $names): ?int
    {
        foreach ($names as $name) {
            $want = strtolower($name);
            foreach (['name', 'description'] as $field) {
                $hit = $ifaces->filter(fn (NetworkInterface $i) => strtolower(trim((string) $i->{$field})) === $want);
                if ($hit->count() === 1) {
                    return $hit->first()->id;
                }
            }
            if (ctype_digit($name)) {
                $hit = $ifaces->firstWhere('if_index', (int) $name);
                if ($hit !== null) {
                    return $hit->id;
                }
            }
            $short = self::shortName($name);
            $hit = $ifaces->filter(fn (NetworkInterface $i) => $short !== null && self::shortName((string) $i->name) === $short);
            if ($hit->count() === 1) {
                return $hit->first()->id;
            }
        }

        return null;
    }

    /** @return Collection<int, NetworkInterface> */
    private function interfacesOf(int $deviceId): Collection
    {
        return $this->interfaces[$deviceId] ??= NetworkInterface::where('device_id', $deviceId)
            ->get(['id', 'device_id', 'if_index', 'name', 'description']);
    }

    /** "core1.example.net" -> "core1". */
    private static function firstLabel(string $name): string
    {
        return explode('.', $name)[0];
    }

    /** "GigabitEthernet1/0/1" and "Gi1/0/1" -> "gi1/0/1". Null for a name without a number. */
    private static function shortName(string $name): ?string
    {
        if (preg_match('/^([A-Za-z]+)[\s-]*(\d[\d\/.:]*)$/', trim($name), $m) !== 1) {
            return null;
        }

        return strtolower(substr($m[1], 0, 2)).$m[2];
    }
}
