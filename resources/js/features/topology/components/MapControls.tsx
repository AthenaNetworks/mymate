import { Panel, useReactFlow } from '@xyflow/react';
import { Plus, Minus, CornersOut } from '@phosphor-icons/react';

/**
 * Bespoke zoom/fit cluster - a rounded glass pill matching the map toolbar, in place of the
 * stock React Flow <Controls> (whose default buttons are an instant "this is a React Flow app"
 * tell). Uses the flow instance for zoom + fit.
 */
export function MapControls() {
    const { zoomIn, zoomOut, fitView } = useReactFlow();
    const btn = 'grid h-8 w-8 place-items-center text-white/55 transition-colors duration-200 ease-fluid hover:bg-white/5 hover:text-white active:scale-95';

    return (
        <Panel position="bottom-left" className="!m-4">
            <div className="flex flex-col overflow-hidden rounded-full bg-[#0d0d11]/80 shadow-[0_8px_24px_-8px_rgba(0,0,0,0.7)] ring-1 ring-white/10 backdrop-blur-xl">
                <button onClick={() => zoomIn({ duration: 200 })} title="Zoom in" className={btn}>
                    <Plus weight="bold" className="h-4 w-4" />
                </button>
                <div className="mx-2 h-px bg-white/10" />
                <button onClick={() => zoomOut({ duration: 200 })} title="Zoom out" className={btn}>
                    <Minus weight="bold" className="h-4 w-4" />
                </button>
                <div className="mx-2 h-px bg-white/10" />
                <button onClick={() => fitView({ duration: 400, padding: 0.3 })} title="Fit to view" className={btn}>
                    <CornersOut weight="bold" className="h-4 w-4" />
                </button>
            </div>
        </Panel>
    );
}
