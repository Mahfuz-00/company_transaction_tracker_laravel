/**
 * numberFormatter.js - the single source of truth for turning a raw number into
 * a display string (separators, decimals, abbreviation, currency symbol).
 *
 * WHY IT EXISTS
 * Each institution configures its own number style (decimal/thousands
 * separators, precision, and how large figures collapse to "1.23 Mil" or
 * "1.2 Crore"). Rather than scatter that logic, every money/quantity label in
 * the app funnels through the default export below, driven by the global
 * settings object.
 *
 * PUBLIC API
 *  - formatNumber(value, settings, currencies) (default export):
 *      value      - the raw number (or numeric string).
 *      settings   - the institution's formatting config, notably:
 *                     decimal_separator, thousands_separator,
 *                     precision / decimal_precision / compact,
 *                     currency_code, symbol_position,
 *                     abbreviated, abbreviations, force_abbreviated,
 *                     numbering_system ('short' | 'indian' | 'east_asian' | 'long'),
 *                     abbreviation_threshold.
 *      currencies - [{ code, symbol }] used to resolve the currency symbol.
 *
 * The helpers below (numberWithSeparators, abbreviateShortScale,
 * abbreviateIndian, abbreviateEastAsian, placeSymbol) are module-private; only
 * formatNumber is exported.
 */

function numberWithSeparators(value, decimalSep='.', thousandSep=',', precision=2) {
    const n = Number(value) || 0;
    const fixed = n.toFixed(precision);
    let [intPart, decPart] = fixed.split('.');
    // insert thousands separator
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep || '');
    return decPart !== undefined ? intPart + (precision > 0 ? decimalSep + decPart : '') : intPart;
}

function abbreviateShortScale(value, precision=2) {
    const abs = Math.abs(value);
    if (abs >= 1e12) return (value / 1e12).toFixed(precision) + ' Tril';
    if (abs >= 1e9) return (value / 1e9).toFixed(precision) + ' Bil';
    if (abs >= 1e6) return (value / 1e6).toFixed(precision) + ' Mil';
    if (abs >= 1e3) return (value / 1e3).toFixed(precision) + ' K';
    return value.toFixed(precision);
}

function abbreviateIndian(value, precision=2) {
    const abs = Math.abs(value);
    if (abs >= 1e7) return (value / 1e7).toFixed(precision) + ' Crore';
    if (abs >= 1e5) return (value / 1e5).toFixed(precision) + ' Lac';
    if (abs >= 1e3) return (value / 1e3).toFixed(precision) + ' K';
    return value.toFixed(precision);
}

function abbreviateEastAsian(value, precision=2) {
    const abs = Math.abs(value);
    if (abs >= 1e8) return (value / 1e8).toFixed(precision) + ' Yi';
    if (abs >= 1e4) return (value / 1e4).toFixed(precision) + ' Wan';
    return value.toFixed(precision);
}

export default function formatNumber(value, settings = {}, currencies = []) {
    const s = settings || {};
    const decimalSep = s.decimal_separator ?? '.';
    const thousandSep = s.thousands_separator === undefined ? ',' : s.thousands_separator;

    // Money in a dense table doesn't need cents on every row. `compact` drops
    // the decimals, which keeps columns narrow and easy to scan. Pair it with
    // `abbreviated` + `abbreviations` for K/Mil scales on big figures.
    const precision = Number(
        s.precision ?? (s.compact ? 0 : s.decimal_precision ?? 2)
    );

    let symbol = '';
    if (s.currency_code) {
        const c = currencies.find(c => c.code === s.currency_code);
        if (c) symbol = c.symbol || c.code || '';
    }

    // ------------------------------------------------------------------
    // Threshold system.
    //
    // `abbreviation_threshold` is the magnitude at which a number switches
    // from its full form (123,456) to the compact scale (123.46 K). The admin
    // sets this in global settings. A value of 0 means "always abbreviate";
    // `null`/undefined falls back to 1000 (only thousands and above shrink),
    // so the default behaviour never abbreviates a small number like 250.
    // ------------------------------------------------------------------
    const threshold = s.abbreviation_threshold === null
        || s.abbreviation_threshold === undefined
        ? 1000
        : Math.max(0, Number(s.abbreviation_threshold));

    const magnitude = Math.abs(Number(value) || 0);
    const crossesThreshold = magnitude >= threshold;

    // Abbreviated formats (compact implied), but only once the figure crosses
    // the configured threshold - unless the caller forces it with
    // `force_abbreviated` (used by the "Abbreviated Output" preview).
    const wantsAbbreviation = (s.abbreviated && s.abbreviations !== false)
        || s.force_abbreviated === true;

    if (wantsAbbreviation && (crossesThreshold || s.force_abbreviated === true)) {
        let formatted;
        switch (s.numbering_system) {
            case 'indian': formatted = abbreviateIndian(value, precision); break;
            case 'east_asian': formatted = abbreviateEastAsian(value, precision); break;
            case 'long': // fallback to short for now
            case 'short':
            default: formatted = abbreviateShortScale(value, precision); break;
        }
        // add symbol placement
        return placeSymbol(formatted, symbol, s.symbol_position);
    }

    // Standard full number formatting: first create with US separators, then replace
    const useThousand = thousandSep || '';
    const formatted = numberWithSeparators(value, '.', ',');
    // Swap the US separators produced above for the configured ones.
    const [intPart, decPart] = formatted.split('.');
    const intWithCustom = intPart.replace(/,/g, useThousand);
    const final = precision > 0 ? intWithCustom + (decimalSep + (decPart || '').slice(0, precision)) : intWithCustom;

    return placeSymbol(final, symbol, s.symbol_position);
}

function placeSymbol(formattedValue, symbol, position = 'before') {
    if (!symbol) return formattedValue;
    switch (position) {
        case 'before_space': return `${symbol}\u00A0${formattedValue}`;
        case 'after': return `${formattedValue}${symbol}`;
        case 'after_space': return `${formattedValue}\u00A0${symbol}`;
        case 'before':
        default:
            return `${symbol}${formattedValue}`;
    }
}
