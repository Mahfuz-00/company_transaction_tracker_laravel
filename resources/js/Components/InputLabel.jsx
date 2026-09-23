/**
 * InputLabel — the accessible caption for a form field.
 *
 * A styled `<label>` wrapper. It accepts the text either as a `value` string or
 * as `children`, so both usages below are valid:
 *
 *     <InputLabel value="Email" htmlFor="email" />
 *     <InputLabel htmlFor="email">Email</InputLabel>
 *
 * Props:
 *   - value?: string      The label text (alternative to `children`).
 *   - className?: string  Extra classes, appended last.
 *   - children            Label text when `value` is not supplied.
 *   - ...props            Spread onto the `<label>` (e.g. `htmlFor`).
 */
export default function InputLabel({
    value,
    className = '',
    children,
    ...props
}) {
    // An explicit `value` wins; otherwise fall back to `children`.
    return (
        <label
            {...props}
            className={`block text-sm font-medium text-gray-700 ${className}`}
        >
            {value ? value : children}
        </label>
    );
}
