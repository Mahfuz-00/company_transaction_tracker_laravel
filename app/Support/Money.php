<?php

namespace App\Support;

use App\Models\Institution;

/**
 * Server-side money formatting that mirrors resources/js/Utils/numberFormatter.js
 * so a figure looks identical in the UI and in an export.
 */
class Money
{
    /**
     * Format a monetary value with the institution's currency settings,
     * mirroring resources/js/Utils/numberFormatter.js so a figure looks the same
     * on the server (exports, PDFs) as it does in the browser UI.
     *
     * The `$settings` array is the institution's currency configuration and
     * accepts these keys:
     *
     *   - `symbol`               the currency symbol shown, e.g. "৳" or "$".
     *   - `symbol_position`      where the symbol goes relative to the number:
     *                            "before", "before_space", "after" or
     *                            "after_space" (see place()).
     *   - `decimal_precision`    digits after the decimal point (default 2);
     *                            ignored when `$compact` is true.
     *   - `decimal_separator`    character between the whole and fractional
     *                            parts, e.g. "." or ",".
     *   - `thousands_separator`  character between digit groups, e.g. "," or " "
     *                            (an empty string groups nothing).
     *
     * When `$settings` is null the effective global currency settings are used
     * (Institution::currencySettings(), defaults merged in), so the symbol is
     * never blank. `$compact = true` forces zero decimals — the dense-table case
     * where showing cents on every row would only add noise.
     */
    public static function format(float|int|string|null $value, ?array $settings = null, bool $compact = false): string
    {
        $settings ??= (Institution::current()?->currencySettings() ?? Institution::DEFAULT_CURRENCY_SETTINGS);

        $symbol = $settings['symbol'] ?? '';
        $position = $settings['symbol_position'] ?? 'before';
        $precision = $compact
            ? 0
            : (int) ($settings['decimal_precision'] ?? 2);
        $decimalSep = $settings['decimal_separator'] ?? '.';
        $thousandSep = $settings['thousands_separator'] ?? ',';

        $number = number_format((float) $value, $precision, $decimalSep, $thousandSep);

        return self::place($number, $symbol, $position);
    }

    /**
     * Attach the currency symbol to an already-formatted number according to
     * `$position` — the same four placements the JS `placeSymbol()` understands:
     *
     *   before        ->  ৳1,500.00
     *   before_space  ->  ৳ 1,500.00
     *   after         ->  1,500.00৳
     *   after_space   ->  1,500.00 ৳
     *
     * An empty symbol (no currency configured) returns the bare number.
     */
    protected static function place(string $number, string $symbol, string $position): string
    {
        if ($symbol === '') {
            return $number;
        }

        return match ($position) {
            'before_space' => $symbol . ' ' . $number,
            'after' => $number . $symbol,
            'after_space' => $number . ' ' . $symbol,
            default => $symbol . $number,
        };
    }
}
