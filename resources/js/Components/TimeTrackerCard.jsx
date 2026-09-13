export default function TimeTrackerCard({ time = '01:24:08' }) {
    return (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100 text-center">
            <div className="text-xs text-gray-500">Time Tracker</div>
            <div className="text-2xl font-mono font-semibold text-gray-800 mt-2">{time}</div>
            <div className="mt-4 flex items-center justify-center gap-3">
                <button className="px-3 py-1 rounded bg-green-600 text-white">Start</button>
                <button className="px-3 py-1 rounded bg-red-600 text-white">Stop</button>
            </div>
        </div>
    );
}
