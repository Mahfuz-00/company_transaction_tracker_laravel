<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An FX RATE SNAPSHOT for a currency pair on a given day.
 *
 * Snapshots (rather than one live rate) exist so a figure reported last month
 * does not silently change when today's rate moves, and so a finance reviewer can
 * always ask "what rate did we use, and on what date?".
 *
 * This model is deliberately NOT tenant-scoped: an exchange rate is a global fact,
 * not an institution's data. Any institution may read it; only the SSA writes it.
 */
class FxRate extends Model
{
    protected $fillable = [
        'base_code',
        'quote_code',
        'rate',
        'effective_on',
        'source',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:10',
        'effective_on' => 'date',
    ];

    /**
     * The rate to use for a pair on (or before) a date.
     *
     * "Most recent snapshot on or before `$on`" is the lookup finance expects: if
     * no rate was recorded today, yesterday's still stands.
     */
    public static function lookup(string $base, string $quote, ?string $on = null): ?float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        // A pair with itself is always 1 - no lookup needed.
        if ($base === $quote) {
            return 1.0;
        }

        $row = static::query()
            ->where('base_code', $base)
            ->where('quote_code', $quote)
            ->when($on !== null, fn ($q) => $q->where('effective_on', '<=', $on))
            ->orderByDesc('effective_on')
            ->first();

        return $row ? (float) $row->rate : null;
    }

    /**
     * Convert an amount, trying the direct pair first and then the INVERSE
     * (USD->BDT falling back to BDT->USD). Returns null when no rate is known, so
     * a caller can decide whether to omit or flag the figure rather than silently
     * treating it as 1:1.
     */
    public static function convert(float $amount, string $from, string $to, ?string $on = null): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $amount;
        }

        $direct = static::lookup($from, $to, $on);
        if ($direct !== null) {
            return round($amount * $direct, 2);
        }

        $inverse = static::lookup($to, $from, $on);
        if ($inverse !== null && $inverse != 0.0) {
            return round($amount / $inverse, 2);
        }

        return null;
    }

    /** Record/replace a snapshot for a pair on a day. */
    public static function record(string $base, string $quote, float $rate, ?string $on = null, string $source = 'manual', ?int $by = null): self
    {
        return static::updateOrCreate(
            [
                'base_code' => strtoupper($base),
                'quote_code' => strtoupper($quote),
                'effective_on' => $on ?? now()->toDateString(),
            ],
            [
                'rate' => $rate,
                'source' => $source,
                'created_by' => $by,
            ]
        );
    }
}
