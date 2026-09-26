import { VersionPanel } from '../../backups/components/BackupsView';
import { Empty } from './ui';
import type { Device } from '../../../types';

/** The device's stored config versions, diffs and full config: the Backups page viewer, for one device. */
export function ConfigTab({ device }: { device: Device }) {
    if (!device.backup_enabled) {
        return <Empty>Config backups are off for this device. Turn them on from its inspector on the map, then its versions and diffs show here.</Empty>;
    }
    return (
        <div className="flex h-[75vh] min-h-[28rem] flex-col rounded-2xl bg-white/[0.02] p-4 ring-1 ring-white/[0.06]">
            <VersionPanel device={device} />
        </div>
    );
}
