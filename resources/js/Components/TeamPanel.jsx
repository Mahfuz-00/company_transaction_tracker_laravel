/**
 * TeamPanel — a "Team Collaboration" card listing members with their current task.
 *
 * Static presentation only: it does no fetching. If the parent passes no members,
 * it falls back to a built-in demo list so a fresh dashboard still looks complete.
 *
 * Props:
 *   - members?: Array<{ name: string, role: string }>  Rows to show. Defaults to
 *                                                     an empty array.
 */
export default function TeamPanel({ members = [] }) {
    // Prefer the caller's data; otherwise render the placeholder list. `? :` is
    // the JS ternary — an inline if/else expression.
    const sample = members.length ? members : [
        { name: 'Alexandra Deff', role: 'Working on Github Project Repository' },
        { name: 'Edwin Adenike', role: 'Integrate User Authentication' },
        { name: 'Isaac Oluwatemilorun', role: 'Search and Filter' },
    ];

    return (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
            <h4 className="text-sm font-semibold text-gray-700">Team Collaboration</h4>
            {/* Each member renders one row: an initials bubble beside the name and
                role. `split(' ').map(n => n[0])` takes the first letter of every
                word, then `slice(0, 2).join('')` keeps at most two letters. */}
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
