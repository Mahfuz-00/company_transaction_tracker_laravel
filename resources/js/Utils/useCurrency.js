import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Global currency settings.
 *
 * The Software Super Admin configures these once; they arrive on every Inertia
 * response via the shared `currency` prop, so every module formats money
 * identically without a per-page lookup. localStorage is kept only as a
 * fallback for the brief moment before the first response lands.
 */
export function useCurrencySettings() {
    const { props } = usePage();
    const shared = props?.currency;

    const [settings, setSettings] = useState(() => {
        if (shared) return shared;
        try {
            const raw = localStorage.getItem('currency_settings');
            if (raw) return JSON.parse(raw);
        } catch (e) {
            // ignore
        }
        return { symbol: '৳', position: 'before' };
    });

    // Server value always wins once it is available.
    useEffect(() => {
        if (!shared) return;
        setSettings(shared);
        try { localStorage.setItem('currency_settings', JSON.stringify(shared)); } catch (e) {}
    }, [shared]);

    const save = (next) => {
        const merged = { ...settings, ...next };
        setSettings(merged);
        try { localStorage.setItem('currency_settings', JSON.stringify(merged)); } catch (e) {}
    };

    return [settings, save];
}

export function formatCurrencyValue(value, currency) {
    if (value === undefined || value === null || isNaN(value)) return '0.00';

    console.log('Currency value formatting and settings:', value, currency);

    // Safely support both 'symbol'/'sign' and 'symbol_position'/'position'
    const symbol = currency?.symbol ?? currency?.sign ?? '$';
    const position = currency?.symbol_position ?? currency?.position ?? 'before';
    const precision = typeof currency?.decimal_precision === 'number' ? currency.decimal_precision : 2;
    const opts = { minimumFractionDigits: precision, maximumFractionDigits: precision };
    const formattedNumber = Number(value).toLocaleString(undefined, opts);

    // use non-breaking space for spaced placements
    if (position === 'before') return `${symbol}${formattedNumber}`;
    if (position === 'before_space') return `${symbol}\u00A0${formattedNumber}`;
    if (position === 'after') return `${formattedNumber}${symbol}`;
    if (position === 'after_space') return `${formattedNumber}\u00A0${symbol}`;

    return `${symbol}${formattedNumber}`;
}

export default useCurrencySettings;
