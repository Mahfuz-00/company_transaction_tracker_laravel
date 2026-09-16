import React from 'react';

/**
 * Loading feedback primitives.
 *
 * Skeletons for first paint (content not yet known) and spinners for
 * in-flight actions (submitting, regenerating). Keeping both here means
 * every module reports progress the same way.
 */

/* ------------------------------------------------------------------ *
 * Spinner
 * ------------------------------------------------------------------ */

export function Spinner({ className = 'h-4 w-4', label }) {
    return (
        <svg
            className={`animate-spin ${className}`}
            viewBox="0 0 24 24"
            fill="none"
            role="status"
            aria-label={label || 'Loading'}
        >
            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
            <path
                d="M4 12a8 8 0 018-8"
                stroke="currentColor"
                strokeWidth="4"
                strokeLinecap="round"
                className="opacity-90"
            />
        </svg>
    );
}

/* ------------------------------------------------------------------ *
 * Skeleton primitives
 * ------------------------------------------------------------------ */

export function SkeletonLine({ className = 'h-4 w-full' }) {
    return <div className={`animate-pulse rounded bg-slate-200/80 ${className}`} />;
}

export function SkeletonCircle({ className = 'h-9 w-9' }) {
    return <div className={`animate-pulse rounded-full bg-slate-200/80 ${className}`} />;
}

/** A block of placeholder rows, shaped like the table it stands in for. */
export function TableSkeleton({ rows = 6, columns = 5 }) {
    return (
        <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm" aria-busy="true">
            <div className="border-b border-slate-100 bg-slate-50 px-6 py-3">
                <SkeletonLine className="h-3 w-48" />
            </div>
            <div className="divide-y divide-slate-100">
                {Array.from({ length: rows }).map((_, rowIndex) => (
                    <div key={rowIndex} className="flex items-center gap-4 px-6 py-4">
                        <SkeletonCircle />
                        {Array.from({ length: columns - 1 }).map((__, colIndex) => (
                            <SkeletonLine
                                key={colIndex}
                                className={`h-3 ${colIndex === 0 ? 'w-32' : colIndex === columns - 2 ? 'w-20' : 'w-24'}`}
                            />
                        ))}
                        <div className="ml-auto flex gap-3">
                            <SkeletonLine className="h-3 w-10" />
                            <SkeletonLine className="h-3 w-10" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/** Placeholder cards, for dashboard-style metric rows. */
export function CardSkeleton({ count = 4, className = '' }) {
    // Explicit class names: Tailwind scans source text, so a template literal
    // like `lg:grid-cols-${n}` would never be compiled into the stylesheet.
    const columnClasses = {
        1: 'lg:grid-cols-1',
        2: 'lg:grid-cols-2',
        3: 'lg:grid-cols-3',
        4: 'lg:grid-cols-4',
    }[Math.min(Math.max(count, 1), 4)];

    return (
        <div className={`grid grid-cols-1 gap-5 sm:grid-cols-2 ${columnClasses} ${className}`} aria-busy="true">
            {Array.from({ length: count }).map((_, index) => (
                <div key={index} className="rounded-2xl border-slate-200 bg-white p-5 shadow-sm">
                    <SkeletonLine className="h-3 w-24" />
                    <SkeletonLine className="mt-3 h-7 w-32" />
                    <SkeletonLine className="mt-2 h-3 w-40" />
                </div>
            ))}
        </div>
    );
}

/** Chart area placeholder. */
export function ChartSkeleton({ className = 'h-72' }) {
    return (
        <div className={`flex items-end gap-2 ${className}`} aria-busy="true">
            {[45, 70, 55, 85, 60, 75, 50, 65, 90, 58, 72, 48].map((height, index) => (
                <div
                    key={index}
                    className="flex-1 animate-pulse rounded-t bg-slate-200/70"
                    style={{ height: `${height}%`, animationDelay: `${index * 60}ms` }}
                />
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Full-page overlay, for blocking operations (exports, report builds)
 * ------------------------------------------------------------------ */

export function LoadingOverlay({ show, message = 'Working...' }) {
    if (!show) return null;

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 backdrop-blur-xs"
            role="alertdialog"
            aria-busy="true"
            aria-live="polite"
        >
            <div className="flex flex-col items-center gap-3 rounded-2xl border-slate-100 bg-white px-8 py-6 shadow-xl">
                <Spinner className="h-7 w-7 text-indigo-600" />
                <p className="text-sm font-semibold text-slate-700">{message}</p>
            </div>
        </div>
    );
}
