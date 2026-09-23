import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

/**
 * SettingsLayout — the shared chrome for every Settings page.
 *
 * A thin wrapper over AuthenticatedLayout that: supplies a default page header
 * (the "Settings" title, overridable per page) and centres the content in a
 * consistent max-width container. Pages supply only their inner content as
 * `children`; the sidebar/nav around it comes from AuthenticatedLayout. This is
 * the nested-layout pattern — one layout wrapping another layout.
 *
 * Props:
 *   - children        The page's own content.
 *   - title?: string  Header text. Defaults to 'Settings'.
 */
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