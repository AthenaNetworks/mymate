// My Mate logomark - the letter "M" drawn as a live network map (5 nodes + 4 links),
// echoing the topology canvas and the emerald link-colour ramp. Inline SVG so it stays
// crisp at any size and needs no extra asset request. Source assets: public/logomark.svg.
type Props = {
    /** Pixel size of the square tile. */
    size?: number;
    className?: string;
};

export function Logomark({ size = 32, className }: Props) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 64 64"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label="My Mate"
            className={className}
        >
            <defs>
                <linearGradient id="mm-tile" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stopColor="#0c0f14" />
                    <stop offset="1" stopColor="#060608" />
                </linearGradient>
                <linearGradient id="mm-stroke" x1="18" y1="18" x2="46" y2="46" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stopColor="#6ee7b7" />
                    <stop offset="1" stopColor="#10b981" />
                </linearGradient>
                <radialGradient id="mm-glow" cx="0.5" cy="0.42" r="0.6">
                    <stop offset="0" stopColor="#10b981" stopOpacity="0.28" />
                    <stop offset="1" stopColor="#10b981" stopOpacity="0" />
                </radialGradient>
            </defs>

            <rect x="0.5" y="0.5" width="63" height="63" rx="16" fill="url(#mm-tile)" />
            <rect x="0.5" y="0.5" width="63" height="63" rx="16" fill="url(#mm-glow)" />
            <rect x="0.75" y="0.75" width="62.5" height="62.5" rx="15.5" stroke="#ffffff" strokeOpacity="0.1" />

            <polyline
                points="18,46 24,18 32,38 40,18 46,46"
                fill="none"
                stroke="url(#mm-stroke)"
                strokeWidth="3.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <g>
                <circle cx="18" cy="46" r="3.4" fill="#34d399" />
                <circle cx="24" cy="18" r="3.4" fill="#6ee7b7" />
                <circle cx="32" cy="38" r="3.4" fill="#34d399" />
                <circle cx="40" cy="18" r="3.4" fill="#6ee7b7" />
                <circle cx="46" cy="46" r="3.4" fill="#34d399" />
            </g>
            <g fill="#06170f">
                <circle cx="18" cy="46" r="1.3" />
                <circle cx="24" cy="18" r="1.3" />
                <circle cx="32" cy="38" r="1.3" />
                <circle cx="40" cy="18" r="1.3" />
                <circle cx="46" cy="46" r="1.3" />
            </g>
        </svg>
    );
}
