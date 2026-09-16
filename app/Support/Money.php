<?php

namespace App\Support;

use App\Models\Institution;

/**
 * Server-side money formatting that mirrors resources/js/Utils/numberFormatter.js
 * so a figure looks identical in the UI and in an export.
 */
class Money
{
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

    protected static function place(string $number, string $symbol, string $position): string
    {
        if ($symbol === '') {
            return $number;
        }

        return match ($position) {
            'before_space' => $symbol.' '.$number,
            'after' => $number.$symbol,
            'after_space' => $number.' '.$symbol,
            default => $symbol.$number,
        };
    }
}
