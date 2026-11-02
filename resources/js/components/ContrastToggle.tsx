import { CircleHalf } from '@phosphor-icons/react';
import { toggleContrast, useContrast } from '../lib/theme';

/** High-contrast toggle. Sits beside the theme toggle in the top bar and on the sign-in screen. */
export function ContrastToggle({ className }: { className?: string }) {
    const high = useContrast() === 'high';
    return (
        <button
            onClick={toggleContrast}
            title={high ? 'Switch to standard contrast' : 'Switch to high contrast'}
            aria-label="High contrast"
            aria-pressed={high}
            className={
                className ??
                `mm-hc-btn rounded-lg p-1.5 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 ${high ? 'bg-white/10 text-white' : 'text-white/45'}`
            }
        >
            <CircleHalf weight={high ? 'fill' : 'bold'} className="h-4 w-4" />
            <span className="mm-hc-label">Contrast</span>
        </button>
    );
}
