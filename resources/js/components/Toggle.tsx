/**
 * The one on/off switch used across the app. The knob is anchored with an explicit `left`
 * and moved by a translate that exactly matches the track (w-11 track − w-5 knob − 2×2px
 * inset = 20px = translate-x-5), so it never depends on a fragile static position. Every
 * toggle should use this rather than hand-rolling the markup.
 */
export function Toggle({
    checked,
    onChange,
    disabled = false,
    label,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    disabled?: boolean;
    label?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-fluid disabled:cursor-not-allowed disabled:opacity-50 ${
                checked ? 'bg-emerald-500' : 'bg-white/15'
            }`}
        >
            <span
                className={`absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-fluid ${
                    checked ? 'translate-x-5' : 'translate-x-0'
                }`}
            />
        </button>
    );
}
