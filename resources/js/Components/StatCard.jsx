export default function StatCard({ title, value, delta, accent = 'green' }) {
    const accentMap = {
        green: 'border-green-400/40',
        red: 'border-red-400/40',
        indigo: 'border-indigo-400/40',
    };

    return (
        <div className={`p-6 bg-white rounded-xl shadow-sm border-l-4 ${accentMap[accent] || accentMap.indigo}`}>
            <p className="text-sm text-gray-600 font-medium">{title}</p>
            <h3 className="text-3xl font-bold mt-2">{value}</h3>
            {delta ? <p className="text-xs text-gray-500 mt-1">{delta}</p> : null}
        </div>
    );
}
