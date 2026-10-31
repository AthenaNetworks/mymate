import { NodeResizer, type NodeProps } from '@xyflow/react';
import { X } from '@phosphor-icons/react';
import { imageAssetUrl } from '../../../lib/imageAssets';

export type MapImageNodeData = {
    imageId: number;
    assetId: number;
    name: string;
    width: number; // flow px; height follows the aspect ratio
    aspect: number; // height / width of the source image
    editable: boolean; // admin
    onResized?: (width: number, x: number, y: number) => void;
    onRemove?: () => void;
};

/**
 * A library image placed on the map - a logo, a legend, a photo (GitHub #53). An admin drags it
 * like any node, resizes it from the corners when selected (aspect kept), and removes it with the
 * x. It only ever shows the image: no handles, so nothing can be linked to it.
 */
export function MapImageNode({ data, selected }: NodeProps) {
    const d = data as unknown as MapImageNodeData;
    const height = d.width * d.aspect;
    return (
        <div className="group relative" style={{ width: d.width, height }}>
            {d.editable && (
                <NodeResizer
                    isVisible={selected}
                    keepAspectRatio
                    minWidth={16}
                    minHeight={16 * d.aspect}
                    lineClassName="!border-emerald-400/60"
                    handleClassName="!h-2.5 !w-2.5 !rounded-sm !border-emerald-300 !bg-emerald-400"
                    onResizeEnd={(_e, p) => d.onResized?.(Math.round(p.width), p.x, p.y)}
                />
            )}
            <img
                src={imageAssetUrl(d.assetId)}
                alt={d.name}
                draggable={false}
                className="pointer-events-none h-full w-full select-none object-contain"
            />
            {d.editable && d.onRemove && (
                <button
                    onClick={(e) => {
                        e.stopPropagation();
                        d.onRemove?.();
                    }}
                    title="Remove this image from the map"
                    className="absolute -right-2 -top-2 grid h-5 w-5 place-items-center rounded-full bg-zinc-900 text-white/70 opacity-100 ring-1 ring-white/20 transition hover:text-rose-300 lg:opacity-0 lg:group-hover:opacity-100"
                >
                    <X weight="bold" className="h-3 w-3" />
                </button>
            )}
        </div>
    );
}
