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
    const precision = Number(s.decimal_precision ?? 2);
    const decimalSep = s.decimal_separator ?? '.';
    const thousandSep = s.thousands_separator === undefined ? ',' : s.thousands_separator;

    let symbol = '';
    if (s.currency_code) {
        const c = currencies.find(c => c.code === s.currency_code);
        if (c) symbol = c.symbol || c.code || '';
    }

    // Abbreviated formats
    if (s.abbreviations && s.abbreviated) {
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
    // replace separators accordingly
    let [intPart, decPart] = formatted.split('.');
    if (decimalSep !== '.') {
        // swap decimal sep
    }
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
