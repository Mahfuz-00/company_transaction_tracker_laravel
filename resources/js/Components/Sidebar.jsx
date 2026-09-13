import { Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

export default function Sidebar({ user }) {
    return (
        <aside className="w-72 flex-shrink-0 h-screen sticky top-0 left-0 flex flex-col justify-between px-4 py-6 bg-white">
            <div>
                <div className="flex items-center gap-3 mb-6">
                    <ApplicationLogo className="h-12 w-12 object-contain" />
                    <div>
                        <div className="text-lg font-bold text-gray-800">Transaction Tracker</div>
                        <div className="text-xs text-gray-500">Manage your finances</div>
                    </div>
                </div>

                <nav className="space-y-2">
                    <Link href={route('dashboard')} className="flex items-center gap-3 px-4 py-3 rounded-lg bg-gray-50 text-gray-900 font-medium hover:bg-gray-100">
                        <svg className="h-5 w-5 text-green-600" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 13h8V3H3v10zM3 21h8v-6H3v6zM13 21h8V11h-8v10zM13 3v6h8V3h-8z" fill="currentColor"/></svg>
                        <span>Dashboard</span>
                    </Link>

                    <Link href={route('analytics')} className="flex items-center gap-3 px-4 py-3 rounded-lg text-gray-700 hover:bg-gray-50">
                        <svg className="h-5 w-5 text-indigo-500" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3v18h18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/><path d="M7 14v-4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/><path d="M12 14v-8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/><path d="M17 18V8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/></svg>
                        <span>Analytics</span>
                    </Link>
                </nav>
            </div>

            <div className="mt-6">
                <div className="border-t pt-4">
                    <div className="flex items-center gap-3 px-2">
                        <div className="h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center text-sm text-gray-600">{user && user.name ? user.name.split(' ').map(n=>n[0]).slice(0,2).join('') : 'U'}</div>
                        <div className="flex-1">
                            <div className="text-sm font-medium text-gray-800">{user?.name || 'User'}</div>
                            <div className="text-xs text-gray-500">{user?.email || ''}</div>
                        </div>
                    </div>

                    <div className="mt-4 px-2">
                        <Link href={route('logout')} method="post" as="button" className="w-full text-left px-4 py-2 rounded bg-red-50 text-red-600">Log Out</Link>
                    </div>
                </div>
            </div>
        </aside>
    );
}
