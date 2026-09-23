<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * UNIT TESTS for the server-side money formatter (app/Support/Money.php).
 *
 * WHY A PURE UNIT TEST (no Laravel boot, no database):
 * Money::format() accepts an explicit settings array. When one is passed it is
 * self-contained - it never touches the database or the active tenant - so the
 * formatting rules can be verified in isolation and in microseconds. The
 * `Institution::current()` fallback is exercised indirectly by the Feature/HTTP
 * tests; here we pin the pure arithmetic and the symbol-placement rules.
 *
 * The companion frontend formatter lives at resources/js/Utils/numberFormatter.js
 * and must produce identical output for the same settings.
 *
 * @see \App\Support\Money
 */
class MoneyTest extends TestCase
{
    /**
     * A US-style workspace: symbol first, two decimals, dot decimal separator
     * and a comma thousands separator.
     */
    private const US = [
        'symbol' => '$',
        'symbol_position' => 'before',
        'decimal_precision' => 2,
        'decimal_separator' => '.',
        'thousands_separator' => ',',
    ];

    public function test_it_formats_with_the_symbol_before_the_number_by_default(): void
    {
        $this->assertSame('$1,234.50', Money::format(1234.5, self::US));
    }

    public function test_it_supports_every_symbol_position(): void
    {
        // A copy of the base settings with only the position changed, so each
        // assertion isolates the placement rule.
        $this->assertSame('$ 1,234.50', Money::format(1234.5, ['symbol_position' => 'before_space'] + self::US));
        $this->assertSame('1,234.50$', Money::format(1234.5, ['symbol_position' => 'after'] + self::US));
        $this->assertSame('1,234.50 $', Money::format(1234.5, ['symbol_position' => 'after_space'] + self::US));
    }

    public function test_it_drops_the_symbol_entirely_when_none_is_configured(): void
    {
        // A workspace with "no symbol" must render a bare number - not a leading
        // or trailing space where the symbol would have been.
        $this->assertSame('1,234.50', Money::format(1234.5, ['symbol' => ''] + self::US));
    }

    public function test_it_honours_a_european_separator_convention(): void
    {
        // Euro-style: dots group thousands, a comma is the decimal mark, and the
        // symbol trails with a space.
        $euro = [
            'symbol' => '€',
            'symbol_position' => 'after_space',
            'decimal_precision' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ];

        $this->assertSame('1.234,50 €', Money::format(1234.5, $euro));
    }

    public function test_compact_mode_drops_the_decimals(): void
    {
        // Compact mode (used in dense tables / tiles) forces whole units.
        $this->assertSame('$1,235', Money::format(1234.5, self::US, compact: true));
    }

    public function test_it_treats_null_and_string_inputs_as_numbers(): void
    {
        // Money arrives from the DB as strings and from optional relations as
        // null; both must format predictably instead of throwing.
        $this->assertSame('$0.00', Money::format(null, self::US));
        $this->assertSame('$42.00', Money::format('42', self::US));
    }
}
