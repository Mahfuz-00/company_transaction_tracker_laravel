/**
 * InputError — renders a single validation message under a field, or nothing.
 *
 * Normally fed from Inertia's shared `errors` prop, e.g.
 * `{errors.email && <InputError message={errors.email} />}`.
 *
 * Props:
 *   - message?: string    The error text. When falsy the component renders no DOM.
 *   - className?: string  Extra classes, appended last.
 *   - ...props            Spread onto the `<p>`.
 *
 * Returning `null` from a component is React's way of saying "render nothing",
 * so the caller needs no extra conditional around it.
 */
export default function InputError({ message, className = '', ...props }) {
    // A truthy `message` renders the <p>; otherwise the ternary yields null.
    return message ? (
        <p
            {...props}
            className={'text-sm text-pink-300 ' + className}
        >
            {message}
        </p>
    ) : null;
}
