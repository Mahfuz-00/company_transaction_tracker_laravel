import { useNumberFormat } from '@/Utils/useNumberFormat';

/**
 * Currency-aware money formatting for the meal modules.
 *
 * Thin wrapper over the GLOBAL useNumberFormat hook, so money and plain numbers
 * share one implementation and therefore one abbreviation threshold, separator
 * set and precision. Keeping a single source means the admin's settings can
 * never apply to some cards and not others.
 *
 *   const money = useMoney();
 *   money(6167.5)          -> "6,168"        (compact, for tables)
 *   money(6167.5, false)   -> "6,167.50"     (exact, for detail views)
 *   money(1250000)         -> "৳1.25 Mil"    (once past the threshold)
 */
export default function useMoney() {
    const { money } = useNumberFormat();

    return money;
}
