/**
 * StatCard — a compact KPI tile: a label, a headline value and an optional delta.
 *
 * The `accent` prop selects a colour + icon preset (green ↑, red ↓, indigo
 * neutral), so a dashboard row of these can mix "up", "down" and "neutral"
 * statistics. Exported both named and as the default.
 *
 * Props:
 *   - title: string    Small caption above the value.
 *   - value: node      The headline figure (number or pre-formatted string).
 *   - delta?: node     Optional sub-line, e.g. "+12% this week".
 *   - accent?: 'green' | 'red' | 'indigo'  Colour/icon preset; defaults 'indigo'.
 */
export function StatCard({ title, value, delta, accent = 'indigo' }) {
    // Style configurations based on accent prop
    const styles = {
        green: {
            text: 'text-emerald-600',
            bg: 'bg-emerald-50',
            border: 'border-emerald-100/60',
            icon: (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
            )
        },
        red: {
            text: 'text-rose-600',
            bg: 'bg-rose-50',
            border: 'border-rose-100/60',
            icon: (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
            )
        },
        indigo: {
            text: 'text-indigo-600',
            bg: 'bg-indigo-50',
            border: 'border-indigo-100/60',
            icon: (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            )
        }
    };

    const currentStyle = styles[accent] || styles.indigo;

    return (
        <div className="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition-all flex items-center justify-between">
            <div>
                <p className="text-xs font-bold uppercase tracking-wider text-slate-400">{title}</p>
                <h3 className={`text-2xl font-extrabold mt-1 ${currentStyle.text}`}>
                    {value}
                </h3>
                {delta && <p className="text-xs font-medium text-slate-500 mt-1">{delta}</p>}
            </div>
            <div className={`w-12 h-12 rounded-xl ${currentStyle.bg} ${currentStyle.text} flex items-center justify-center border ${currentStyle.border}`}>
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    {currentStyle.icon}
                </svg>
            </div>
        </div>
    );
}

export default StatCard;