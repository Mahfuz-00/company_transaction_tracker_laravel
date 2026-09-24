import React, { useEffect } from 'react';

/**
 * Accessible modal shell shared by the meal modules.
 * Closes on Escape and on backdrop click; locks page scroll while open.
 */
export default function Modal({ open, onClose, title, description, children, footer, maxWidth = 'max-w-2xl' }) {
    useEffect(() => {
        if (!open) return undefined;

        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose?.();
        };

        document.addEventListener('keydown', onKeyDown);
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in"
            role="dialog"
            aria-modal="true"
            aria-label={typeof title === 'string' ? title : undefined}
            onClick={onClose}
        >
            <div
                className={`w-full ${maxWidth} flex flex-col max-h-[90vh] overflow-hidden rounded-2xl border-slate-100 bg-white shadow-xl animate-rise`}
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-6 py-4">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">{title}</h3>
                        {description && (
                            <p className="mt-0.5 text-xs text-slate-500">{description}</p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close dialog"
                        className="rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-200/55 hover:text-slate-600"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto p-6">{children}</div>

                {footer && (
                    <div className="flex items-center justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}
