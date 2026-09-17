import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Global currency settings - a tiny reactive store.
 *
 * The Software Super Admin configures currency once; the server shares the
 * resulting object on every Inertia response as the `currency` prop.
 *
 * THE BUG THIS FIXES
 * ------------------
 * Previously every component called `useState(shared)` locally. When the
 * Currency Manager saved, only ITS OWN state updated - every other module
 * (already mounted with its own copy) kept formatting with the old symbol and
 * separators until a full page reload. The save appeared "broken".
 *
 * The fix: a single module-level store (the source of truth) plus a set of
 * subscriber callbacks. `setCurrency()` notifies every subscriber, so a save in
 * one place re-renders formatting everywhere instantly. The server value, when
 * it arrives, is pushed through the same `setCurrency()` path so there is
 * exactly one way state changes.
 */

const STORAGE_KEY = 'currency_settings';

const FALLBACK = { symbol: '৳', position: 'before' };

/** The single shared settings object. */
let current = null;

/** Every mounted consumer's re-render callback. */
const subscribers = new Set();

function readStorage() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) return JSON.parse(raw);
    } catch (e) {
        // ignore - private mode / disabled storage
    }
    return null;
}

function writeStorage(value) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch (e) {
        // ignore
    }
}

/**
 * The initial value, resolved once per page load: a previously cached value
 * (so formatting is right on first paint) else the safe default.
 */
function initial() {
    if (current) return current;
    current = readStorage() || FALLBACK;
    return current;
}

/**
 * Publish new settings to every subscriber and persist them. This is the only
 * function that mutates state, so the cache, the localStorage copy and every
 * component can never drift apart.
 */
export function setCurrency(next) {
    const merged = { ...initial(), ...(next || {}) };
    current = merged;
    writeStorage(merged);

    subscribers.forEach((notify) => notify(merged));

    return merged;
}

/**
 * Subscribe to global currency settings.
 *
 * Returns `[settings, save]`, matching the previous API so no call site had to
 * change. `save()` now updates the SHARED store, which is what makes currency
 * changes propagate globally.
 */
export function useCurrencySettings() {
    const { props } = usePage();
    const shared = props?.currency;

    const [settings, setSettings] = useState(initial);

    // Subscribe once per component instance.
    useEffect(() => {
        subscribers.add(setSettings);
        // Catch up in case the store changed between render and effect.
        setSettings(initial());

        return () => {
            subscribers.delete(setSettings);
        };
    }, []);

    // Server value always wins once it is available. Routing it through
    // setCurrency keeps one mutation path and updates every other consumer.
    useEffect(() => {
        if (!shared) return;
        setCurrency(shared);
    }, [shared]);

    // Save merges over the current shared value, so a partial update from the
    // manager never drops fields the manager did not send.
    const save = (next) => setCurrency({ ...initial(), ...(next || {}) });

    return [settings, save];
}

export function formatCurrencyValue(value, currency) {
    if (value === undefined || value === null || isNaN(value)) return '0.00';

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
