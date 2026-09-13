export default function ProgressCard({ percent = 41, title = 'Project Progress' }) {
    const radius = 36;
    const stroke = 8;
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
