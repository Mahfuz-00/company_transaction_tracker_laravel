import { useState, useEffect } from 'react';

export function useCurrencySettings() {
    const [settings, setSettings] = useState({ sign: '৳', position: 'before' });

    useEffect(() => {
        try {
            const raw = localStorage.getItem('currency_settings');
            if (raw) setSettings(JSON.parse(raw));
        } catch (e) {
            // ignore
        }
    }, []);

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
