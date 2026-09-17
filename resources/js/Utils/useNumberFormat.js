import { useMemo } from 'react';
import { useCurrencySettings } from '@/Utils/useCurrency';
import formatNumber from '@/Utils/numberFormatter';

/**
 * GLOBAL number-formatting helper.
 *
 * One hook every dashboard, table and chart consumes, so the admin's
 * "Abbreviation Threshold" (and separators / precision / numbering system) is
 * applied consistently across the ENTIRE app.
 *
 * Why this exists
 * ---------------
 * Previously `formatNumber(value)` was called directly in several places with NO
 * settings, so it silently used hard-coded defaults and ignored the configured
 * threshold. Other places used `formatCurrencyValue`, which does not abbreviate
 * at all. This hook reads the ONE shared currency/format settings object and
 * exposes both a plain-number formatter and a money formatter.
 *
 *   const { number, money } = useNumberFormat();
 *   number(1250)        -> "1.25 K"   (once past the threshold)
 *   money(1250)         -> "৳1.25 K"
 *   number(250)         -> "250"      (below the threshold, stays full)
 */
export function useNumberFormat() {
    const [settings] = useCurrencySettings();

    return useMemo(() => {
        const stored = settings || {};

        // Normalise the settings object once: the Currency Manager writes
        // `sign`/`position`, formatNumber reads `symbol_position`/`symbol`.
        const base = {
            ...stored,
            symbol_position: stored.symbol_position ?? stored.position ?? 'before',
        };

        /**
         * Format a plain number (no currency symbol) honouring the global
         * threshold. `compact` drops the decimals for dense tables.
         */
        const number = (value, compact = false, overrides = {}) =>
            formatNumber(Number(value) || 0, {
                ...base,
                symbol: '', // explicitly no symbol for plain numbers
                ...(compact ? { compact: true } : {}),
                ...overrides,
            });

        /** Format as money using the SAME global threshold + separators. */
        const money = (value, compact = true, overrides = {}) =>
            formatNumber(Number(value) || 0, {
                ...base,
                ...(compact ? { compact: true } : {}),
                ...overrides,
            });

        return { number, money, settings: base };
    }, [settings]);
}

export default useNumberFormat;
