/**
 * AnalyticsPanel — a small, self-contained "weekly activity" chart card.
 *
 * Draws a fixed seven-bar chart from plain divs (no charting library), so it can
 * be dropped onto any dashboard with zero setup. Like a Flutter `StatelessWidget`
 * it owns no state: it only reads its props and returns the view tree to draw.
 * That returned markup is JSX, and every `{ ... }` inside it is a live
 * JavaScript expression (e.g. `{title}`) rather than a placeholder string.
 *
 * Props:
 *   - title?: string  Card heading. Defaults to 'Project Analytics' — the `=` in
 *                     the destructured parameter list is React's default-value
 *                     pattern.
 */
export default function AnalyticsPanel({ title = 'Project Analytics' }) {
    return (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
            <div className="flex items-center justify-between">
                <h4 className="text-sm font-semibold text-gray-700">{title}</h4>
                <span className="text-xs text-gray-500">Weekly</span>
            </div>

            <div className="mt-4 grid grid-cols-7 gap-2 items-end h-28">
                {/* `[1..7]` is just a throwaway list to iterate over — each bar's
                    height/colour is derived from its index (n) so the mock chart
                    stays deterministic. The `key` prop is the one thing React
                    needs on a mapped list to tell items apart across re-renders;
                    it is the JSX counterpart of a stable id in a Compose key(). */}
                {[1,2,3,4,5,6,7].map((n)=> (
                    <div key={n} className={`w-full rounded-t ${n%2===0 ? 'bg-green-400' : 'bg-green-200'}`} style={{height: `${20 + n*6}px`}} />
                ))}
            </div>
        </div>
    );
}
