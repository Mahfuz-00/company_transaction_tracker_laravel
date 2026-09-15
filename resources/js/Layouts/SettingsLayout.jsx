import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function SettingsLayout({ children, title = 'Settings' }) {
    return (
        <AuthenticatedLayout header={<h2 className="font-semibold text-xl text-slate-800 leading-tight">{title}</h2>}>
            <div className="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                {/* Main Settings Content */}
                <div>{children}</div>
            </div>
        </AuthenticatedLayout>
    );
}