import React from 'react';

/**
 * Button — the generic, variant-driven action button from the UI kit.
 *
 * One component covers three looks via the `variant` prop. The final class string
 * is composed from three parts: a shared `base`, the chosen `variant`, then any
 * caller `className` last (so callers can still override). Stateless — purely
 * presentational.
 *
 * Props:
 *   - variant?: 'primary' | 'secondary' | 'ghost'  Visual style; defaults 'primary'.
 *   - disabled?: boolean  Disables the button; opacity + `aria-disabled` reflect it.
 *   - className?: string  Extra classes, appended last.
 *   - children            Button label / content.
 *   - ...props            Spread onto the `<button>` (onClick, type, …).
 */
export default function Button({ children, variant = 'primary', className = '', disabled = false, ...props }) {
  // Shared skeleton applied to every variant (layout + accessible focus ring).
  const base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500';
  // Per-variant colour classes, picked by the `variant` prop just below.
  const variants = {
    primary: 'bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-60',
    secondary: 'bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-60',
    ghost: 'bg-transparent text-gray-800 hover:bg-gray-50',
  };
  // Look the variant up by name, falling back to `primary` for an unknown value.
  const v = variants[variant] || variants.primary;
  // Spread first, then set disabled/className/aria-disabled explicitly so this
  // component's behaviour wins; `aria-disabled` surfaces the state to assistive
  // technology even when the click is only blocked visually.
  return (
    <button
      {...props}
      disabled={disabled}
      className={`${base} ${v} ${className}`}
      aria-disabled={disabled ? 'true' : undefined}
    >
      {children}
    </button>
  );
}
