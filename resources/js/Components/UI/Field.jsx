import React from 'react';

/**
 * Consistent labelled form control used across the meal modules.
 * Renders the right element per `type` and always surfaces the error,
 * so validation feedback stays uniform no matter which field it is.
 */
export default function Field({
    label,
    name,
    error,
    hint,
    type = 'text',
    options = [],
    required = false,
    className = '',
    ...props
}) {
    const baseInput =
        'w-full rounded-lg border-slate-300 px-3.5 py-2 text-sm text-slate-900 outline-none transition-all placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20';
    const invalid = error ? 'border-rose-300 focus:border-rose-500 focus:ring-rose-500/20' : '';

    return (
        <div className={className}>
            <label htmlFor={name} className="mb-1.5 block text-sm font-semibold text-slate-700">
                {label}
                {!required && <span className="font-normal text-slate-400"> (optional)</span>}
            </label>

            {type === 'textarea' ? (
                <textarea
                    id={name}
                    name={name}
                    rows={3}
                    className={`${baseInput} ${invalid}`}
                    {...props}
                />
            ) : type === 'select' ? (
                <select id={name} name={name} className={`${baseInput} ${invalid}`} {...props}>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            ) : (
                <input
                    id={name}
                    name={name}
                    type={type}
                    className={`${baseInput} ${invalid}`}
                    {...props}
                />
            )}

            {error ? (
                <p role="alert" className="mt-1 text-xs text-rose-500">
                    {error}
                </p>
            ) : hint ? (
                <p className="mt-1 text-xs text-slate-400">{hint}</p>
            ) : null}
        </div>
    );
}
