export default function TeamPanel({ members = [] }) {
    const sample = members.length ? members : [
        { name: 'Alexandra Deff', role: 'Working on Github Project Repository' },
        { name: 'Edwin Adenike', role: 'Integrate User Authentication' },
        { name: 'Isaac Oluwatemilorun', role: 'Search and Filter' },
    ];

    return (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
            <h4 className="text-sm font-semibold text-gray-700">Team Collaboration</h4>
            <div className="mt-4 space-y-3">
                {sample.map((m, i) => (
                    <div key={i} className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center text-sm text-gray-600">{m.name.split(' ').map(n=>n[0]).slice(0,2).join('')}</div>
                        <div>
                            <div className="text-sm font-medium text-gray-800">{m.name}</div>
                            <div className="text-xs text-gray-500">{m.role}</div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
