import React, { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

/**
 * The header notification bell.
 *
 * Reads the unread count + recent items from the shared `notifications` prop
 * (see HandleInertiaRequests), so it needs no request on first paint. It
 * subscribes to Inertia's router so the badge refreshes after any navigation
 * (which is when a new notification is most likely to have arrived).
 */

const KIND_ICON = {
    claim_submitted: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    claim_approved: 'M5 13l4 4L19 7',
    claim_rejected: 'M6 18L18 6M6 6l12 12',
    expense_approved: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8',
    deposit_updated: 'M19 14l-7 7m0 0l-7-7m7 7V3',
    announcement: 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z',
};

const KIND_TONE = {
    claim_submitted: 'bg-amber-50 text-amber-600',
    claim_approved: 'bg-emerald-50 text-emerald-600',
    claim_rejected: 'bg-rose-50 text-rose-600',
    expense_approved: 'bg-emerald-50 text-emerald-600',
    deposit_updated: 'bg-sky-50 text-sky-600',
    announcement: 'bg-[var(--accent-soft)] text-[var(--accent)]',
};

export default function NotificationBell() {
    const { notifications } = usePage().props;
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    const unread = notifications?.unreadCount ?? 0;
    const items = notifications?.items ?? [];

    // Close on outside click.
    useEffect(() => {
        if (!open) return undefined;

        const onClick = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, [open]);

    const markAll = (e) => {
        e.preventDefault();
        e.stopPropagation();
        router.post(route('notifications.readAll'), {}, { preserveScroll: true });
    };

    const openItem = (item) => {
        // Mark read, then follow the link if there is one.
        router.patch(route('notifications.read', item.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                if (item.url) router.visit(item.url);
            },
        });
    };

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label={`Notifications${unread > 0 ? ` (${unread} unread)` : ''}`}
                aria-expanded={open}
                className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border-slate-200 text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-800"
            >
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                {unread > 0 && (
                    <span className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 top-12 z-50 w-80 origin-top-right overflow-hidden rounded-xl border-slate-200 bg-white shadow-xl animate-rise sm:w-96">
                    <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                        <span className="text-sm font-bold text-slate-900">Notifications</span>
                        {unread > 0 && (
                            <button
                                type="button"
                                onClick={markAll}
                                className="text-xs font-semibold text-[var(--accent)] hover:underline"
                            >
                                Mark all read
                            </button>
                        )}
                    </div>

                    <div className="max-h-96 overflow-y-auto">
                        {items.length > 0 ? (
                            <ul className="divide-y divide-slate-100">
                                {items.map((item) => (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            onClick={() => openItem(item)}
                                            className={`flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-slate-50 ${item.read ? '' : 'bg-[var(--accent-soft)]/40'}`}
                                        >
                                            <span className={`mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg ${KIND_TONE[item.kind] || 'bg-slate-100 text-slate-500'}`}>
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={KIND_ICON[item.kind] || 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'} />
                                                </svg>
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center gap-1.5">
                                                    <span className="truncate text-sm font-semibold text-slate-800">{item.title}</span>
                                                    {!item.read && <span className="h-1.5 w-1.5 flex-shrink-0 rounded-full bg-[var(--accent)]" />}
                                                </span>
                                                {item.body && <span className="mt-0.5 block truncate text-xs text-slate-500">{item.body}</span>}
                                                <span className="mt-0.5 block text-[11px] text-slate-400">{item.created_human}</span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-4 py-10 text-center text-xs italic text-slate-400">
                                You have no notifications yet.
                            </p>
                        )}
                    </div>

                    <Link
                        href={route('notifications.index')}
                        onClick={() => setOpen(false)}
                        className="block border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-center text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                    >
                        View all notifications
                    </Link>
                </div>
            )}
        </div>
    );
}
