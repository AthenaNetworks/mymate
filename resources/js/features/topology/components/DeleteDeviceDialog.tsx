import { TrashSimple } from '@phosphor-icons/react';
import { ConfirmDialog } from '../../../components/Dialog';
import { useDeleteDevice } from '../../devices/api/deleteDevice';
import { useDeviceList } from '../../devices/api/getDevices';
import { useIsAdmin } from '../../auth/api/auth';
import { useLinks } from '../api/getLinks';
import { selectDevice } from '../../../lib/shellStore';
import { pushToast } from '../../../lib/toast';
import type { Device } from '../../../types';

/**
 * Confirm deleting a device outright (GitHub #45) - the map's destructive twin of "Remove from
 * this map", shared by the node menu and the inspector so both say exactly the same thing.
 *
 * The map already has a reversible hide, so the dialog's job is to make plain that this is the
 * other kind: it counts what the delete takes with it (links, placements, history) and warns
 * when the device has children, which survive and fall back to no parent.
 */
export function DeleteDeviceDialog({ device, onClose }: { device: Device; onClose: () => void }) {
    const isAdmin = useIsAdmin();
    const del = useDeleteDevice();
    // Only the count matters, so ask for one row and read the total.
    const { data: childPage } = useDeviceList({ parent_id: device.id, per_page: 1, fields: 'summary' });
    const { data: links } = useLinks();

    if (!isAdmin) return null;

    const children = childPage?.meta.total ?? 0;
    const linkCount = (links ?? []).filter((l) => l.a_device_id === device.id || l.b_device_id === device.id).length;
    const maps = device.maps_count ?? 0;

    const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
    // Only name what this device actually has, so the sentence stays true for a bare node.
    const losses = [linkCount > 0 && plural(linkCount, 'link'), maps > 0 && plural(maps, 'map placement')].filter(Boolean) as string[];

    return (
        <ConfirmDialog
            title="Delete device"
            icon={<TrashSimple weight="light" className="h-5 w-5" />}
            message={
                <>
                    Delete <span className="font-semibold text-white/85">{device.name}</span>? It stops being monitored, and its interfaces and
                    history are removed{losses.length > 0 && <>, along with its {losses.join(' and ')}</>}. This can't be undone.
                    {children > 0 && (
                        <>
                            {' '}
                            <span className="text-amber-300/90">
                                {plural(children, 'device')} below it {children === 1 ? 'stays' : 'stay'} monitored but {children === 1 ? 'loses its' : 'lose their'}{' '}
                                parent
                            </span>{' '}
                            - re-home {children === 1 ? 'it' : 'them'} afterwards.
                        </>
                    )}
                </>
            }
            confirmLabel="Delete"
            tone="danger"
            busy={del.isPending}
            onConfirm={() =>
                del.mutate(device.id, {
                    onSuccess: () => {
                        selectDevice(null); // the inspector would otherwise re-home to some other device
                        pushToast({ title: `Deleted ${device.name}`, tone: 'info' });
                        onClose();
                    },
                    onError: () => {
                        pushToast({ title: "Couldn't delete the device", tone: 'down' });
                        onClose();
                    },
                })
            }
            onClose={onClose}
        />
    );
}
