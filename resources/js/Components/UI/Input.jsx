import React from 'react';

export default function Input({ id, label, error, className = '', ...props }) {
  const base = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
  return (
    <label className="block">
      {label && <span className="text-sm text-gray-700 mb-1 block">{label}</span>}
      <input id={id} className={`${base} ${className}`} {...props} />
      {error && <div role="alert" className="text-red-500 text-xs mt-1">{error}</div>}
    </label>
  );
}
