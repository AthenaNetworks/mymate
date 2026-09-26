import { MapPin } from '@phosphor-icons/react';
import { useRevertToSnmpLocation } from '../api/snmpLocation';
import { pushToast } from '../../../lib/toast';
import type { Device } from '../../../types';

/**
 * "Use SNMP location" (GitHub #22). A device placed by hand (dragged on the geo map, or coords typed
 * into the editor) is a manual pin and its SNMP / RouterOS location stops moving it. This hands it
 * back. Only rendered for a manual pin; callers gate it to admins (the API enforces that too).
 * `onMoved` lets an open edit form pick up the new coordinates so a later Save doesn't undo it.
 */
export function UseSnmpLocationButton({ device, onMoved }: { device: Device; onMoved?: (d: Device) => void }) {
    const revert = useRevertToSnmpLocation();
    if (device.geo_source !== 'manual') return null;

    const known = device.snmp_latitude != null && device.snmp_longitude != null;
    const title = known
        ? `Move it to ${device.snmp_latitude}, ${device.snmp_longitude} from its SNMP location`
        : "Its SNMP location hasn't given us coordinates yet - it'll move on the next discovery pass if it does";

    return (
        <button
            type="button"
            title={title}
            disabled={revert.isPending}
            onClick={() =>
                revert.mutate(device.id, {
                    onSuccess: ({ device: d, moved }) => {
                        onMoved?.(d);
                        pushToast(
                            moved
                                ? { title: 'Moved to its SNMP location', tone: 'up' }
                                : { title: 'Handed back to SNMP', detail: 'It moves once its SNMP location reports coordinates.', tone: 'up' },
                        );
                    },
                    onError: () => pushToast({ title: "Couldn't switch it back to SNMP", tone: 'down' }),
                })
            }
            className="flex items-center gap-1 text-[11px] text-emerald-300/80 transition-colors hover:text-emerald-200 disabled:opacity-40"
        >
            <MapPin weight="bold" className="h-3 w-3" /> Use SNMP location
        </button>
    );
}
