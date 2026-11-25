import type { AlertScope, DeviceType, NetworkMap, ScopeRule } from '../types';

/**
 * Helpers for targeting scopes (alert policies, maintenance windows, custom sensors). A saved
 * scope is either the original single choice or a combination (types / maps / devices, with
 * exclusions); see AlertScope. The editor works on combinations only, so an old scope is converted
 * when it's opened - and saved that way, with the same meaning (a single map stays without its
 * sub-maps).
 */

export const SCOPE_DEVICE_TYPES: { value: DeviceType; label: string }[] = [
    { value: 'router', label: 'Router' },
    { value: 'switch', label: 'Switch' },
    { value: 'ap', label: 'Access point' },
    { value: 'server', label: 'Server' },
    { value: 'internet', label: 'Internet' },
    { value: 'unknown', label: 'Unknown' },
];

/** Fully spelled-out combination: every list present, submaps a real boolean. */
export interface FullScope {
    include: Required<ScopeRule>;
    exclude: Required<ScopeRule>;
}

const emptyRule = (): Required<ScopeRule> => ({ device_types: [], map_ids: [], submaps: false, device_ids: [] });

/** Any saved scope (or none) as a full combination, with the same meaning. */
export function toCombination(scope: AlertScope | null | undefined): FullScope {
    const rule = (r?: ScopeRule): Required<ScopeRule> => ({
        device_types: r?.device_types ?? [],
        map_ids: r?.map_ids ?? [],
        submaps: r?.submaps ?? false,
        device_ids: r?.device_ids ?? [],
    });
    if (!scope) return { include: emptyRule(), exclude: emptyRule() };
    if (scope.include || scope.exclude) return { include: rule(scope.include), exclude: rule(scope.exclude) };
    switch (scope.type) {
        case 'device_type':
            return { include: { ...emptyRule(), device_types: scope.device_type ? [scope.device_type] : [] }, exclude: emptyRule() };
        case 'map':
            // The old single-map scope never included sub-maps.
            return { include: { ...emptyRule(), map_ids: scope.map_id ? [scope.map_id] : [] }, exclude: emptyRule() };
        case 'devices':
            return { include: { ...emptyRule(), device_ids: scope.device_ids ?? [] }, exclude: emptyRule() };
        default:
            return { include: emptyRule(), exclude: emptyRule() };
    }
}

const ruleEmpty = (r: Required<ScopeRule>) => r.device_types.length === 0 && r.map_ids.length === 0 && r.device_ids.length === 0;

/** True when a scope covers every device with nothing excluded. */
export function isFleetWide(scope: AlertScope | null | undefined): boolean {
    const s = toCombination(scope);
    return ruleEmpty(s.include) && ruleEmpty(s.exclude);
}

/** The devices a scope lists by name (to include), eg for picking their ports. */
export function listedDeviceIds(scope: AlertScope | null | undefined): number[] {
    return toCombination(scope).include.device_ids;
}

function typeNames(types: DeviceType[]): string {
    return types.map((t) => SCOPE_DEVICE_TYPES.find((o) => o.value === t)?.label.toLowerCase() ?? t).join(', ');
}

function mapNames(ids: number[], maps: NetworkMap[] | undefined, submaps: boolean): string {
    const names = ids.map((id) => maps?.find((m) => m.id === id)?.name ?? `map ${id}`);
    const shown = names.length > 2 ? `${names.slice(0, 2).join(', ')} +${names.length - 2}` : names.join(', ');
    return `${shown}${submaps ? ' (+ sub-maps)' : ''}`;
}

const count = (n: number, one: string) => `${n} ${one}${n === 1 ? '' : 's'}`;

/**
 * A short label for a list row, eg "routers, switches on Site (+ sub-maps) + 2 devices, except
 * access points, Lab". `maps` turns map ids into names when available.
 */
export function describeScope(scope: AlertScope | null | undefined, maps?: NetworkMap[]): string {
    const { include: inc, exclude: exc } = toCombination(scope);

    let what: string;
    if (inc.device_types.length === 0 && inc.map_ids.length === 0) {
        what = inc.device_ids.length > 0 ? count(inc.device_ids.length, 'device') : 'all devices';
    } else {
        const types = inc.device_types.length > 0 ? typeNames(inc.device_types) : 'all devices';
        what = inc.map_ids.length > 0 ? `${types} on ${mapNames(inc.map_ids, maps, inc.submaps)}` : types;
        if (inc.device_ids.length > 0) what += ` + ${count(inc.device_ids.length, 'device')}`;
    }

    const except = [
        exc.device_types.length > 0 ? typeNames(exc.device_types) : null,
        exc.map_ids.length > 0 ? mapNames(exc.map_ids, maps, exc.submaps) : null,
        exc.device_ids.length > 0 ? count(exc.device_ids.length, 'device') : null,
    ].filter(Boolean);

    return except.length > 0 ? `${what}, except ${except.join(', ')}` : what;
}
