export default function AnalyticsPanel({ title = 'Project Analytics' }) {
    return (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
            <div className="flex items-center justify-between">
                <h4 className="text-sm font-semibold text-gray-700">{title}</h4>
                <span className="text-xs text-gray-500">Weekly</span>
            </div>

            <div className="mt-4 grid grid-cols-7 gap-2 items-end h-28">
                {[1,2,3,4,5,6,7].map((n)=> (
                    <div key={n} className={`w-full rounded-t ${n%2===0 ? 'bg-green-400' : 'bg-green-200'}`} style={{height: `${20 + n*6}px`}} />
                ))}
            </div>
        </div>
    );
}
