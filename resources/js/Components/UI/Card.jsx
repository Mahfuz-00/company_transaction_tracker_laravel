import React from 'react';

export default function Card({ children, className = '', ...props }) {
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
