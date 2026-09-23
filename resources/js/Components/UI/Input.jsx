import React from 'react';

/**
 * Input — a labelled text field with optional inline error text (UI kit).
 *
 * The `<label>` WRAPS the `<input>`, so clicking the label focuses the field and
 * the two are associated without needing a shared `id` (the `id` prop is still
 * forwarded). The label text and the error are rendered conditionally.
 *
 * Props:
 *   - label?: string      Caption shown above the field. Omitted → no span.
 *   - error?: string      Validation message shown below. Omitted → nothing.
 *   - id?: string         Forwarded to the `<input>` for explicit label linking.
 *   - className?: string  Extra classes for the `<input>`, appended last.
 *   - ...props            Spread onto the `<input>` (value, onChange, …).
 */
export default function Input({ id, label, error, className = '', ...props }) {
  // Shared field styling; the caller's className is appended so it can override.
  const base = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
  return (
    <label className="block">
      {/* `label && …` renders the span only when a label string was supplied. */}
      {label && <span className="text-sm text-gray-700 mb-1 block">{label}</span>}
      <input id={id} className={`${base} ${className}`} {...props} />
      {/* `role="alert"` makes screen readers announce the error when it appears. */}
      {error && <div role="alert" className="text-red-500 text-xs mt-1">{error}</div>}
    </label>
  );
}
