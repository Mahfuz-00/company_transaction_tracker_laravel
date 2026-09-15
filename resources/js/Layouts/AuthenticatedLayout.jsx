import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import Sidebar from '@/Components/Sidebar';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function AuthenticatedLayout({ header, children }) {
    const { auth } = usePage().props;
    const user = auth?.user;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900">
            <div className="flex h-screen">
                <Sidebar user={user} />

                <div className="flex-1 overflow-auto">
                    <div className="px-6 py-6 lg:px-8">
                        {header && (
                            <header className="mb-4">
                                <div className="max-w-full">
                                    {header}
                                </div>
                            </header>
                        )}

                        <main>{children}</main>
                    </div>
                </div>
            </div>
            
            
        </div>
    );
}
