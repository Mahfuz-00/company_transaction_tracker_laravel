/**
 * DangerButton — the destructive-action button (delete / remove / revoke).
 *
 * A thin reusable wrapper over a native `<button>` that bakes in the red
 * "danger" styling, so every destructive action looks identical.
 *
 * Props:
 *   - children            Whatever sits between `<DangerButton>…</DangerButton>`
 *                         arrives here as a prop — the React equivalent of a slot
 *                         or a Compose content lambda. It becomes the label.
 *   - disabled?: boolean  Greys the button out and makes it non-interactive.
 *   - className?: string  Extra classes, appended last so they win.
 *   - ...props            Everything else is spread onto the `<button>` (onClick,
 *                         type, …).
 */
export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    // `disabled && 'opacity-25'` yields the class only while disabled (`false`
    // renders nothing in JSX). The caller's `className` is appended AFTER the
    // base string, so extra utilities can override the baked-in styles.
    return (
        <button
            {...props}
            className={`inline-flex items-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white transition duration-150 ease-in-out hover:bg-red-500 focus:outline-none ${disabled && 'opacity-25'} ` + className}
            disabled={disabled}
        >
            {children}
        </button>
    );
}
