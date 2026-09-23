/**
 * SecondaryButton — the low-emphasis counterpart to PrimaryButton.
 *
 * A styled native `<button>` with a subtle translucent surface, for actions that
 * sit next to a primary one (Cancel, Back, "Add more").
 *
 * Props:
 *   - type?: string       HTML button type. Defaults to 'button' deliberately, so
 *                         it never submits an enclosing form by accident.
 *   - children            Button label.
 *   - disabled?: boolean  Disables the control.
 *   - className?: string  Extra classes, appended last.
 *   - ...props            Spread onto the `<button>` (onClick, …).
 */
export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}) {
    // `type` is applied after the spread, so the default 'button' wins unless the
    // caller passes one explicitly.
    return (
        <button
            {...props}
            type={type}
            className={`inline-flex items-center rounded-md border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white/90 transition duration-150 ease-in-out hover:bg-white/10 focus:outline-none ${className}`}
            disabled={disabled}
        >
            {children}
        </button>
    );
}
