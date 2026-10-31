import { memo } from 'react';
import { ViewportPortal, useStore } from '@xyflow/react';

type Props = {
    src: string;
    width: number;
    height: number;
    x: number;
    y: number;
    scale: number;
    opacity: number;
    tile?: boolean;
};

/**
 * Draws a map's background image (GitHub #37) inside the React Flow viewport, so it pans and
 * zooms with the map for free - the viewport's own transform moves it, nothing here re-renders on
 * pan or zoom. It sits in its own portal layer rather than being a node, so changing it never
 * touches the node list. z-index -1 puts it under the edges and nodes (the portal layer is the
 * last child of the viewport); pointer-events none keeps it from ever stealing a click or a drag.
 *
 * Positions are flow coordinates, same as the nodes. Used by the logged-in canvas and the public
 * wallboard alike.
 */
export const MapBackgroundLayer = memo(function MapBackgroundLayer({ src, width, height, x, y, scale, opacity, tile }: Props) {
    if (tile) {
        return <TiledBackground src={src} width={width} height={height} x={x} y={y} scale={scale} opacity={opacity} />;
    }
    return (
        <ViewportPortal>
            <img
                src={src}
                alt=""
                aria-hidden
                draggable={false}
                decoding="async"
                style={{
                    position: 'absolute',
                    left: 0,
                    top: 0,
                    width,
                    height,
                    maxWidth: 'none', // preflight's img max-width:100% would squash it to the portal box
                    transform: `translate(${x}px, ${y}px) scale(${scale})`,
                    transformOrigin: '0 0',
                    opacity,
                    zIndex: -1,
                    pointerEvents: 'none',
                    userSelect: 'none',
                }}
            />
        </ViewportPortal>
    );
});

/**
 * The same image repeated across the whole canvas, wallpaper style (GitHub #53). Painted in screen
 * space behind the viewport and offset by the pan/zoom, the way React Flow's own dotted
 * <Background> works, rather than as one enormous element in flow space. x/y anchor the pattern.
 */
function TiledBackground({ src, width, height, x, y, scale, opacity }: Omit<Props, 'tile'>) {
    const [tx, ty, zoom] = useStore((s) => s.transform);
    const w = width * scale * zoom;
    const h = height * scale * zoom;
    return (
        <div
            aria-hidden
            style={{
                position: 'absolute',
                inset: 0,
                zIndex: -1,
                pointerEvents: 'none',
                backgroundImage: `url("${src}")`,
                backgroundRepeat: 'repeat',
                backgroundSize: `${w}px ${h}px`,
                backgroundPosition: `${tx + x * zoom}px ${ty + y * zoom}px`,
                opacity,
            }}
        />
    );
}
