/**
 * Checkbox — a styled wrapper around the native `<input type="checkbox">`.
 *
 * Purely presentational: it applies the project's Tailwind classes and forwards
 * every other attribute to the real input, so all normal HTML behaviour
 * (`checked`, `onChange`, `name`, `value`, …) still works. In React such an input
 * is "controlled" when the parent passes `checked` + `onChange`, and
 * "uncontrolled" when the parent lets the DOM hold the value.
 *
 * Props:
 *   - className?: string  Extra Tailwind classes; appended last so callers can
 *                         add to / override the defaults.
 *   - ...props            The remaining props are spread straight onto the input.
 *                         `{...props}` uses JS's rest/spread operator — collect
 *                         the leftover named props into one object and re-apply
 *                         them, much like a Kotlin `vararg`.
 */
export default function Checkbox({ className = '', ...props }) {
    // Spread first, then set `type`/`className` explicitly so the wrapper's own
    // defaults win; the caller's `className` is concatenated so it ADDS utility
    // classes instead of replacing the base ones.
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 ' +
                className
            }
        />
    );
}
