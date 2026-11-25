import { MapTrifold } from '@phosphor-icons/react';
import { SCOPE_DEVICE_TYPES, toCombination } from '../lib/scope';
import type { AlertScope, NetworkMap, ScopeRule } from '../types';

/** Named devices shown before the rest fold into "+N more" (named on hover). */
const DEVICES_SHOWN = 5;

const chip = 'inline-flex max-w-full items-center gap-1 truncate whitespace-nowrap rounded-md px-1.5 text-xs leading-5';
const label = 'pt-0.5 text-[10px] font-semibold uppercase tracking-wider text-white/30';

/**
 * A targeting scope (alert policy, maintenance window, sensor) as rows of chips: "Applies to"
 * with its device types, maps (green, "+ sub-maps") and devices named one by one, then "Except"
 * in rose when anything is excluded. The chips wrap rather than truncate, so nothing is cut off.
 *
 * `deviceNames` names listed devices by id (the policy list's coverage); a listed device missing
 * from it has been deleted since.
 */
export function ScopeChips({
    scope,
    maps,
    deviceNames,
    allLabel = 'All devices',
}: {
    scope: AlertScope | null | undefined;
    maps: NetworkMap[] | undefined;
    deviceNames?: Record<string, string>;
    allLabel?: string;
}) {
    const { include, exclude } = toCombination(scope);
    const hasExclude = exclude.device_types.length + exclude.map_ids.length + exclude.device_ids.length > 0;
    const includeEmpty = include.device_types.length + include.map_ids.length + include.device_ids.length === 0;

    return (
        <div className="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-2 gap-y-1">
            <span className={label}>Applies to</span>
            <div className="flex min-w-0 flex-wrap items-center gap-1">
                {includeEmpty ? <span className="text-xs text-white/60">{allLabel}</span> : <RuleChips rule={include} maps={maps} deviceNames={deviceNames} />}
            </div>
            {hasExclude && (
                <>
                    <span className={label}>Except</span>
                    <div className="flex min-w-0 flex-wrap items-center gap-1">
                        <RuleChips rule={exclude} maps={maps} deviceNames={deviceNames} except />
                    </div>
                </>
            )}
        </div>
    );
}

function RuleChips({
    rule,
    maps,
    deviceNames,
    except = false,
}: {
    rule: Required<ScopeRule>;
    maps: NetworkMap[] | undefined;
    deviceNames?: Record<string, string>;
    except?: boolean;
}) {
    const plain = except ? 'bg-rose-500/10 text-rose-300' : 'bg-white/5 text-white/85';
    const mapTone = except ? 'bg-rose-500/10 text-rose-300 ring-1 ring-rose-400/25' : 'bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-400/20';
    const join = 'px-0.5 text-xs text-white/30';

    const deviceName = (id: number) => deviceNames?.[String(id)] ?? (deviceNames ? `deleted device #${id}` : `device #${id}`);
    const shown = rule.device_ids.slice(0, DEVICES_SHOWN);
    const rest = rule.device_ids.slice(DEVICES_SHOWN);

    return (
        <>
            {rule.device_types.map((t) => (
                <span key={t} className={`${chip} ${plain}`}>
                    {SCOPE_DEVICE_TYPES.find((o) => o.value === t)?.label ?? t}
                </span>
            ))}
            {rule.map_ids.length > 0 && rule.device_types.length > 0 && <span className={join}>{except ? 'or' : 'on'}</span>}
            {rule.map_ids.map((id) => (
                <span key={id} className={`${chip} ${mapTone}`}>
                    <MapTrifold weight="bold" className="h-3 w-3 shrink-0 opacity-80" />
                    {maps?.find((m) => m.id === id)?.name ?? `map #${id}`}
                    {rule.submaps && <span className="text-[10px] opacity-75">+ sub-maps</span>}
                </span>
            ))}
            {shown.length > 0 && (rule.device_types.length > 0 || rule.map_ids.length > 0) && <span className={join}>{except ? 'or' : 'and'}</span>}
            {shown.map((id) => (
                <span key={id} className={`${chip} font-mono text-[11px] ${plain}`}>
                    {deviceName(id)}
                </span>
            ))}
            {rest.length > 0 && (
                <span className={`${chip} cursor-help text-white/40 ring-1 ring-white/10`} title={rest.map(deviceName).join(', ')}>
                    +{rest.length} more
                </span>
            )}
        </>
    );
}
