import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Field from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { Head, Link, router, useForm } from '@inertiajs/react';

/**
 * The full notifications page - every role sees their own.
 * Institution Admins get a composer to broadcast an announcement.
 */

const KIND_TONE = {
    claim_submitted: 'bg-amber-50 text-amber-600',
    claim_approved: 'bg-emerald-50 text-emerald-600',
    claim_rejected: 'bg-rose-50 text-rose-600',
    expense_approved: 'bg-emerald-50 text-emerald-600',
    deposit_updated: 'bg-sky-50 text-sky-600',
    announcement: 'bg-[var(--accent-soft)] text-[var(--accent)]',
};

const KIND_ICON = {
    claim_submitted: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    claim_approved: 'M5 13l4 4L19 7',
    claim_rejected: 'M6 18L18 6M6 6l12 12',
    expense_approved: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8',
    deposit_updated: 'M19 14l-7 7m0 0l-7-7m7 7V3',
    announcement: 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z',
};

export default function Index({ notifications, unreadCount = 0, canAnnounce = false }) {
    const rows = notifications?.data || [];
    const [composeOpen, setComposeOpen] = useState(false);

    const form = useForm({ title: '', body: '' });

    const markAll = () => router.post(route('notifications.readAll'), {}, { preserveScroll: true });

    const markOne = (id) => router.patch(route('notifications.read', id), {}, { preserveScroll: true });

    const remove = (id) => router.delete(route('notifications.destroy', id), { preserveScroll: true });

    const announce = (e) => {
        e.preventDefault();
        form.post(route('notifications.announce'), {
            preserveScroll: true,
            onSuccess: () => { setComposeOpen(false); form.reset(); },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Notifications</h2>
                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                            Claim updates, deposit changes and announcements.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {unreadCount > 0 && (
                            <button
                                type="button"
                                onClick={markAll}
                                className="rounded-lg border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Mark all read
                            </button>
                        )}
                        {canAnnounce && (
                            <button
                                type="button"
                                onClick={() => setComposeOpen(true)}
                                className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90"
                            >
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6" />
                                </svg>
                                New Announcement
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Notifications" />

            <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                {rows.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {rows.map((n) => (
                            <li key={n.id} className={`flex items-start gap-3 px-5 py-4 ${n.read ? '' : 'bg-[var(--accent-soft)]/30'}`}>
                                <span className={`mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg ${KIND_TONE[n.kind] || 'bg-slate-100 text-slate-500'}`}>
                                    <svg className="h-4.5 w-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={KIND_ICON[n.kind] || 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'} />
                                    </svg>
                                </span>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-bold text-slate-800">{n.title}</span>
                                        {!n.read && <span className="h-1.5 w-1.5 rounded-full bg-[var(--accent)]" />}
                                    </div>
                                    {n.body && <p className="mt-0.5 text-sm leading-relaxed text-slate-600">{n.body}</p>}
                                    <div className="mt-1 flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                                        <span>{n.created_human}</span>
                                        {n.url && (
                                            <Link href={n.url} className="font-semibold text-[var(--accent)] hover:underline">
                                                Open
                                            </Link>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-shrink-0 items-center gap-2">
                                    {!n.read && (
                                        <button
                                            type="button"
                                            onClick={() => markOne(n.id)}
                                            className="text-xs font-semibold text-slate-500 hover:text-slate-800"
                                        >
                                            Mark read
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => remove(n.id)}
                                        aria-label="Delete notification"
                                        className="rounded-lg p-1 text-slate-300 transition-colors hover:bg-rose-50 hover:text-rose-500"
                                    >
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="py-16 text-center">
                        <p className="text-sm font-semibold text-slate-600">No notifications yet.</p>
                        <p className="mt-1 text-xs text-slate-400">
                            Claim updates and announcements will appear here.
                        </p>
                    </div>
                )}

                {notifications?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{notifications.from}</strong>–<strong>{notifications.to}</strong> of{' '}
                            <strong>{notifications.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {notifications.links.map((link, index) => (
                                <Link
                                    key={index}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                        ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                                        : link.url
                                            ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                            : 'pointer-events-none border-slate-100 bg-white text-slate-300'
                                        }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Announcement composer (admins) */}
            <Modal
                open={composeOpen}
                onClose={() => setComposeOpen(false)}
                title="New Announcement"
                description="Broadcast to everyone in the active institution."
                footer={
                    <>
                        <button
                            type="button"
                            onClick={() => setComposeOpen(false)}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="announce-form"
                            disabled={form.processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            {form.processing ? 'Sending...' : 'Send Announcement'}
                        </button>
                    </>
                }
            >
                <form id="announce-form" onSubmit={announce} className="space-y-4">
                    <Field
                        label="Title"
                        name="title"
                        required
                        value={form.data.title}
                        error={form.errors.title}
                        placeholder="e.g. Kitchen closed this Friday"
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                    <Field
                        label="Message"
                        name="body"
                        type="textarea"
                        required
                        value={form.data.body}
                        error={form.errors.body}
                        placeholder="Write the announcement..."
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
