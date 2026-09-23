import React from 'react';

/**
 * Card — the UI kit's generic surface/panel container.
 *
 * A styled `<div>` that wraps arbitrary content with the standard card look. It
 * adds no behaviour, only padding and a soft surface, so anything can live inside.
 *
 * Props:
 *   - children            The card contents (passed between the tags).
 *   - className?: string  Extra classes, appended last.
 *   - ...props            Spread onto the `<div>`.
 */
export default function Card({ children, className = '', ...props }) {
  // `role="region"` exposes the card as a landmark to screen readers. The spread
  // sits AFTER role/className so any extra props are still applied (className was
  // destructured out, so it cannot be clobbered by the spread).
  return (
    <div
      role="region"
      className={`bg-white/5 rounded-lg shadow-sm border border-transparent p-4 ${className}`}
      {...props}
    >
      {children}
    </div>
  );
}
