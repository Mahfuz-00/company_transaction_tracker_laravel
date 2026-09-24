import React from 'react';

/**
 * Single source of icon paths for the sidebar (and anywhere else).
 *
 * Icons are stored as path data only, so the shell (svg + viewBox + stroke
 * settings) lives in one place. Add a key here and reference it by name
 * from Utils/navItems.js.
 */
const ICONS = {
    dashboard: {
        fill: true,
        paths: [
            'M3 13h8V3H3v10zM3 21h8v-6H3v6zM13 21h8V11h-8v10zM13 3v6h8V3h-8z',
        ],
    },
    analytics: {
        paths: ['M3 3v18h18', 'M18 17V9', 'M13 17V5', 'M8 17v-3'],
    },
    chart: {
        paths: [
            'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        ],
    },
    plus: {
        paths: ['M12 5v14M5 12h14'],
        strokeWidth: 2.2,
    },
    store: {
        paths: [
            'M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M9 20v-6h6v6',
        ],
    },
    bank: {
        paths: [
            'M3 21h18M4 10h16M5 10V21M19 10V21M9 21v-7M15 21v-7M12 3l9 6H3l9-6z',
        ],
    },
    users: {
        paths: [
            'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-6.93 4 4 0 004 6.93zm6-2a3 3 0 10-2-5.65M9 7a3 3 0 11-6 0 3 3 0 016 0z',
        ],
    },
    building: {
        paths: [
            'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        ],
    },
    download: {
        paths: [
            'M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2',
        ],
    },
    upload: {
        paths: [
            'M12 21V9m0 0l-4 4m4-4l4 4M4 7V5a2 2 0 012-2h12a2 2 0 012 2v2',
        ],
    },
    clipboard: {
        paths: [
            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
        ],
    },
    receipt: {
        paths: [
            'M20 12V8H6a2 2 0 010-4h12v4m0 4v4H6a2 2 0 000 4h12v-4m0-4h-4a2 2 0 000 4h4v-4z',
        ],
    },
    mail: {
        paths: [
            'M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
            'M3 8l9 6 9-6',
        ],
    },
    chevronDown: {
        paths: ['M19 9l-7 7-7-7'],
    },
    settings: {
        paths: [
            'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z',
        ],
        circles: [{ cx: 12, cy: 12, r: 3 }],
    },
};

export default function Icon({ name, className = 'h-5 w-5', ...props }) {
    const icon = ICONS[name];

    // Fail visibly in dev, quietly in production - a missing icon should never
    // take down the whole sidebar.
    if (!icon) {
        if (import.meta.env.DEV) {
            console.warn(`[Icon] Unknown icon "${name}"`);
        }
        return <span className={className} aria-hidden="true" />;
    }

    const paths = icon.paths || [];

    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill={icon.fill ? 'currentColor' : 'none'}
            stroke={icon.fill ? 'none' : 'currentColor'}
            strokeWidth={icon.strokeWidth || 2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {paths.map((d, index) => (
                <path key={index} d={d} />
            ))}

            {icon.circles?.map((circle, index) => (
                <circle
                    key={index}
                    cx={circle.cx}
                    cy={circle.cy}
                    r={circle.r}
                />
            ))}
        </svg>
    );
}
