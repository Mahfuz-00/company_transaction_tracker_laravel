/**
 * PrimaryButton — the default, high-emphasis action button.
 *
 * A styled native `<button>` carrying the brand gradient. Use it for the one main
 * action on a screen; pair it with SecondaryButton for anything less prominent.
 *
 * Props:
 *   - children            Button label (whatever is placed between the tags).
 *   - disabled?: boolean  Dims the button and, via `pointer-events-none`, stops
 *                         clicks entirely.
 *   - className?: string  Extra classes, appended last so they can override.
 *   - ...props            Spread onto the `<button>` (onClick, type, …).
 */
export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    // The disabled branch appends classes conditionally: `opacity-40` fades it
    // and `pointer-events-none` blocks the click, complementing the real
    // `disabled` attribute that also removes it from the tab order.
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-lg border border-transparent bg-gradient-to-r from-sky-400 to-indigo-400 px-4 py-2 text-sm font-semibold text-white shadow-sm transition duration-150 ease-in-out hover:from-sky-500 hover:to-indigo-500 focus:outline-none focus:ring-2 focus:ring-sky-300 active:scale-95 ${
                    disabled ? 'opacity-40 pointer-events-none' : ''
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
