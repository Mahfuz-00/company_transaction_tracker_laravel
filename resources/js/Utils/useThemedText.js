import { useMemo } from 'react';
import { useTheme } from '@/Components/ThemeProvider';
import { themedTextClass } from '@/Components/UI/ThemedText';

/**
 * THEME-AWARE TYPOGRAPHY HOOK.
 *
 * Companion to <ThemedText>. Use it when you need a themed text CLASS STRING
 * rather than a wrapper element - e.g. dropping it onto an existing <td>, an
 * <option>, a template literal, or a component that must stay a specific tag.
 *
 *   const { tx, isDark } = useThemedText();
 *   <td className={tx('muted', 'px-6')}>—</td>
 *   <h3 className={tx('heading')}>Currency</h3>
 *
 * It subscribes to the LIVE theme context, so any change the Theme Customizer
 * applies (accent, light/dark, radius, font, density) causes a re-render and the
 * returned classes always match the active theme.
 *
 * `isDark` is exposed for the rare case that genuinely needs a conditional
 * (e.g. choosing an image asset), NOT for colouring text - prefer a variant.
 */
export default function useThemedText() {
    const { theme } = useTheme();

    const isDark = (theme?.mode || 'light') === 'dark';

    const tx = useMemo(
        () => (variant = 'body', extra = '') => themedTextClass(variant, extra),
        // Re-create only when the mode actually flips; the class strings
        // themselves are static (they resolve through CSS variables).
        [isDark]
    );

    return {
        tx,
        isDark,
        // Convenience: the semantic CSS custom-property names, for inline styles.
        tokens: {
            primary: 'var(--text-primary)',
            secondary: 'var(--text-secondary)',
            muted: 'var(--text-muted)',
            accent: 'var(--accent)',
        },
    };
}
