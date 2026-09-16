import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

/**
 * Institution terminology, shared from HandleInertiaRequests.
 *
 * A dorm shows "Students"; a company shows "Employees". Components call
 * `t('members')` instead of hardcoding the noun, so changing the institution
 * type re-labels the whole UI without touching component code.
 *
 *   const { t, institution } = useTerminology();
 *   <h2>{t('members')}</h2>            // "Students" | "Employees"
 *   <p>{institution?.name}</p>
 */
export default function useTerminology() {
    const { props } = usePage();
    const institution = props?.institution ?? null;
    const terms = institution?.terms ?? {};

    /**
     * Resolve a term key. Falls back to the key itself, then to `fallback`,
     * so a missing entry renders something usable rather than blank.
     */
    const t = useCallback(
        (key, fallback = null) => terms[key] ?? fallback ?? key,
        [terms]
    );

    /** Capitalised alias, for headings and labels. */
    const tTitle = useCallback(
        (key, fallback = null) => {
            const value = terms[key] ?? fallback ?? key;
            return value.charAt(0).toUpperCase() + value.slice(1);
        },
        [terms]
    );

    return useMemo(
        () => ({
            t,
            tTitle,
            terms,
            institution,
            type: institution?.type ?? null,
            typeLabel: institution?.type_label ?? null,
            // Convenience flag for the few places that need a special case.
            isCompany: institution?.type === 'company',
        }),
        [t, tTitle, terms, institution]
    );
}
