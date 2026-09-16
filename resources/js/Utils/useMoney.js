import { useMemo } from 'react';
import { useCurrencySettings } from '@/Utils/useCurrency';
import formatNumber from '@/Utils/numberFormatter';

/**
 * Currency-aware money formatting for the meal modules.
 *
 * Wraps useCurrencySettings + formatNumber so every table and card renders
 * figures identically, and so a single place controls whether cents show.
 *
 *   const money = useMoney();
 *   money(6167.5)          -> "6,168"        (compact, for tables)
 *   money(6167.5, false)   -> "6,167.50"     (exact, for detail views)
 *   money(1250000, false, { abbreviated: true }) -> "1.25 Mil"
 */
export default function useMoney() {
    const [settings] = useCurrencySettings();

    return useMemo(() => {
        const stored = settings || {};

        // The stored settings (written by the Currency Manager) use
        // `sign`/`position`, while formatNumber expects a currency lookup via
        // `currency_code` + `symbol_position`. Bridge the two so the symbol the
        // user configured actually shows up.
        const base = {
            ...stored,
            symbol_position: stored.symbol_position ?? stored.position ?? 'before',
        };

        return function money(value, compact = true, overrides = {}) {
            return formatNumber(Number(value) || 0, {
                ...base,
                ...(compact ? { compact: true } : {}),
                ...overrides,
            });
        };
    }, [settings]);
}
