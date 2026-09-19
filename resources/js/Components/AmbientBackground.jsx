import React from 'react';

/**
 * Ambient background for the guest / auth screens.
 *
 * A soft, mellow wash of drifting gradient orbs - deliberately gentle (low
 * opacity, long durations, small travel) so it feels premium and calm rather
 * than busy. Performance notes:
 *   - only `transform` and `opacity` animate (compositor-friendly, no layout),
 *   - a handful of blurred circles on a fixed, pointer-events-none layer,
 *   - fully disabled under `prefers-reduced-motion`.
 *
 * It renders BEHIND the auth card (z-index -10) and never intercepts clicks.
 */
export default function AmbientBackground() {
    return (
        <>
            <style>{`
                @keyframes ambient-drift-a {
                    0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
                    50% { transform: translate3d(4%, -3%, 0) scale(1.08); }
                }
                @keyframes ambient-drift-b {
                    0%, 100% { transform: translate3d(0, 0, 0) scale(1.05); }
                    50% { transform: translate3d(-5%, 4%, 0) scale(1); }
                }
                @keyframes ambient-drift-c {
                    0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
                    50% { transform: translate3d(3%, 5%, 0) scale(1.1); }
                }
                @keyframes ambient-sheen {
                    0%, 100% { opacity: .35; }
                    50% { opacity: .6; }
                }
                .ambient-orb-a { animation: ambient-drift-a 26s ease-in-out infinite; }
                .ambient-orb-b { animation: ambient-drift-b 32s ease-in-out infinite; }
                .ambient-orb-c { animation: ambient-drift-c 38s ease-in-out infinite; }
                .ambient-sheen { animation: ambient-sheen 18s ease-in-out infinite; }
                @media (prefers-reduced-motion: reduce) {
                    .ambient-orb-a, .ambient-orb-b, .ambient-orb-c, .ambient-sheen { animation: none !important; }
                }
            `}</style>

            <div aria-hidden="true" className="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
                {/* Base mellow wash - soft slate/neutral, never a harsh bright white. */}
                <div className="absolute inset-0 bg-gradient-to-b from-slate-100 via-slate-50 to-white" />

                {/* Drifting gradient orbs, tuned to mellow, low-contrast tones. */}
                <div className="ambient-orb-a absolute -left-24 -top-24 h-[32rem] w-[32rem] rounded-full bg-gradient-to-br from-indigo-200/40 to-sky-200/30 blur-3xl" />
                <div className="ambient-orb-b absolute -right-28 top-1/4 h-[30rem] w-[30rem] rounded-full bg-gradient-to-br from-sky-200/35 to-slate-200/40 blur-3xl" />
                <div className="ambient-orb-c absolute bottom-[-10rem] left-1/3 h-[34rem] w-[34rem] rounded-full bg-gradient-to-tr from-violet-200/30 to-indigo-200/25 blur-3xl" />

                {/* A faint sheen for depth. */}
                <div className="ambient-sheen absolute inset-x-0 top-0 h-64 bg-gradient-to-b from-white/40 to-transparent" />

                {/* Fine grain so large gradients band less. */}
                <div
                    className="absolute inset-0 opacity-[0.015]"
                    style={{
                        backgroundImage:
                            'radial-gradient(circle at 1px 1px, rgba(15,23,42,0.6) 1px, transparent 0)',
                        backgroundSize: '22px 22px',
                    }}
                />
            </div>
        </>
    );
}
