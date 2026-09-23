/**
 * ApplicationLogo — the app's default logo mark.
 *
 * A self-contained SVG (an open ledger/book on a gradient tile) used wherever a
 * generic brand mark is needed, e.g. the sidebar and the landing footer. It has no
 * content of its own: every prop is forwarded onto the root `<svg>`, so callers
 * control size, `className`, etc.
 */
export default function ApplicationLogo(props) {
    return (
        <svg
            viewBox="0 0 48 48"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            {...props}
        >
            {/* Background Rounded Container with Indigo Gradient */}
            <rect width="48" height="48" rx="12" fill="url(#paint0_linear)" />

            {/* Open Ledger / Book Shape */}
            <path
                d="M14 16C14 14.8954 14.8954 14 16 14H22C23.1046 14 24 14.8954 24 16V34C24 35.1046 23.1046 36 22 36H16C14.8954 36 14 35.1046 14 34V16Z"
                fill="white"
                fillOpacity="0.2"
            />
            <path
                d="M24 16C24 14.8954 24.8954 14 26 14H32C33.1046 14 34 14.8954 34 16V34C34 35.1046 33.1046 36 32 36H26C24.8954 36 24 35.1046 24 34V16Z"
                fill="white"
                fillOpacity="0.9"
            />

            {/* Center Spine Shadow Line */}
            <line x1="24" y1="14" x2="24" y2="36" stroke="#4338CA" strokeWidth="1.5" />

            {/* Minimalist Fork/Spoon / Ledger Lines overlay on the right page */}
            <path
                d="M28 20H30M28 24H30M28 28H30"
                stroke="#6366F1"
                strokeWidth="2"
                strokeLinecap="round"
            />

            <defs>
                <linearGradient
                    id="paint0_linear"
                    x1="4"
                    y1="4"
                    x2="44"
                    y2="44"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stopColor="#6366F1" />
                    <stop offset="1" stopColor="#4F46E5" />
                </linearGradient>
            </defs>
        </svg>
    );
}