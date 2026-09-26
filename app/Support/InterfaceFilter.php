<?php

namespace App\Support;

use App\Models\Link;
use App\Models\NetworkInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which interfaces an interface-level alert policy watches (GitHub #22 / #11). Lives in the
 * policy's `params.interfaces` bag:
 *
 *   mode 'all'      - every interface on the in-scope devices (the default, and what
 *                     interface_down always did before this existed)
 *   mode 'linked'   - only interfaces that are an end of a map link, ie the uplinks/backhaul
 *   mode 'match'    - name or description matches one of a comma separated list of globs,
 *                     eg "sfp*, ether1, vlan*" (case-insensitive, * and ? wildcards)
 *   mode 'selected' - an explicit list of interface ids
 *
 * The device scope still applies on top, this only narrows which ports on those devices count.
 */
class InterfaceFilter
{
    public const MODES = ['all', 'linked', 'match', 'selected'];

    /** @param  list<int>  $ids */
    private function __construct(
        public readonly string $mode,
        private readonly array $ids = [],
        private readonly ?string $regex = null,
    ) {}

    /** @param  array<string, mixed>  $params  the policy's whole params bag */
    public static function fromParams(array $params): self
    {
        $bag = is_array($params['interfaces'] ?? null) ? $params['interfaces'] : [];
        $mode = in_array($bag['mode'] ?? null, self::MODES, true) ? $bag['mode'] : 'all';

        return match ($mode) {
            'selected' => new self('selected', array_values(array_map('intval', (array) ($bag['interface_ids'] ?? [])))),
            'match' => new self('match', regex: self::globRegex((string) ($bag['match'] ?? ''))),
            default => new self($mode),
        };
    }

    public function isAll(): bool
    {
        return $this->mode === 'all';
    }

    /**
     * Narrow an interfaces query to what this filter allows. The glob match is done in SQL
     * too (case-insensitive regex on name + description) so a 25k device fleet doesn't pull
     * every port into PHP just to throw most of them away.
     *
     * @param  Builder<NetworkInterface>  $query
     * @return Builder<NetworkInterface>
     */
    public function narrow(Builder $query): Builder
    {
        return match ($this->mode) {
            'selected' => $query->whereIn('interfaces.id', $this->ids),
            'linked' => $query->where(function (Builder $q) {
                $q->whereIn('interfaces.id', Link::whereNotNull('a_interface_id')->select('a_interface_id'))
                    ->orWhereIn('interfaces.id', Link::whereNotNull('b_interface_id')->select('b_interface_id'));
            }),
            // An empty pattern matches nothing rather than everything, a blank box shouldn't
            // quietly turn into "alert on every port".
            'match' => $this->regex === null
                ? $query->whereRaw('1 = 0')
                : $query->where(fn (Builder $q) => $q->whereRaw('interfaces.name ~* ?', [$this->regex])
                    ->orWhereRaw("coalesce(interfaces.description, '') ~* ?", [$this->regex])),
            default => $query,
        };
    }

    /**
     * Turn "sfp*, ether1" into an anchored POSIX regex, or null when there's nothing usable.
     * Everything except * and ? is escaped so an operator can't feed us a broken or
     * pathological regex by accident.
     */
    private static function globRegex(string $patterns): ?string
    {
        $alts = [];
        foreach (preg_split('/[,\s]+/', $patterns) ?: [] as $glob) {
            if ($glob === '') {
                continue;
            }
            $escaped = preg_replace('/[^A-Za-z0-9*?]/', '\\\\$0', $glob);
            $alts[] = strtr((string) $escaped, ['*' => '.*', '?' => '.']);
        }

        return $alts === [] ? null : '^('.implode('|', $alts).')$';
    }
}
