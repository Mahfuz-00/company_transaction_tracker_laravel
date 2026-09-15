import React from 'react';

export default function Button({ children, variant = 'primary', className = '', disabled = false, ...props }) {
  const base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500';
  const variants = {
    primary: 'bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-60',
    secondary: 'bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-60',
    ghost: 'bg-transparent text-gray-800 hover:bg-gray-50',
  };
  const v = variants[variant] || variants.primary;
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
