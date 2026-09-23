/**
 * ProgressCard — a donut chart showing a single completion percentage.
 *
 * Built directly from SVG (no chart library): an outer grey ring plus a coloured
 * arc whose length encodes the value. State-free — it only renders its props.
 *
 * Props:
 *   - percent?: number  Completion 0–100. Defaults to 41.
 *   - title?: string    Caption shown to the right of the ring.
 */
export default function ProgressCard({ percent = 41, title = 'Project Progress' }) {
    // SVG donut geometry. A circle's full length is 2·π·r; we draw only the
    // `percent` fraction of it as a coloured arc by setting the arc's
    // strokeDasharray to `dash gap` (where dash + gap = the whole
    // circumference), then rotate it -90° so the arc starts at 12 o'clock.
    const radius = 36;
    const stroke = 8;
    // Clamp into 0..100 so an out-of-range prop can never overrun the ring.
    const normalized = Math.min(100, Math.max(0, percent));
    const circumference = 2 * Math.PI * radius;
    const dash = (normalized / 100) * circumference;

    return (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100 flex items-center gap-4">
            <div className="shrink-0">
                <svg width="88" height="88" viewBox="0 0 88 88">
                    <g transform="translate(44,44)">
                        <circle r={radius} stroke="#eee" strokeWidth={stroke} fill="none" />
                        <circle r={radius} stroke="#10B981" strokeWidth={stroke} fill="none" strokeLinecap="round" strokeDasharray={`${dash} ${circumference - dash}`} transform="rotate(-90)" />
                        <text x="0" y="4" textAnchor="middle" className="text-sm font-semibold" fill="#111">{percent}%</text>
                    </g>
                </svg>
            </div>
            <div>
                <div className="text-sm font-semibold text-gray-700">{title}</div>
                <div className="text-xs text-gray-500 mt-1">Completed / In Progress / Pending</div>
            </div>
        </div>
    );
}
