import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

/**
 * TextInput — the project's standard single-line text field.
 *
 * A styled native `<input>` that also lets the PARENT call `focus()` on it. That
 * requires a ref, and function components can't receive a ref by default — so:
 *
 *   - `forwardRef` wraps the component so a `ref` passed by the parent is handed
 *     through instead of being dropped.
 *   - Inside, `useRef` holds the real DOM node (`localRef`), and
 *     `useImperativeHandle` publishes a small curated API (`focus`) on the
 *     parent's ref rather than exposing the whole node.
 *   - `isFocused` is a convenience boolean: pass `true` to autofocus on mount.
 *
 * Props:
 *   - type?: string       Input type. Defaults to 'text'.
 *   - isFocused?: boolean Autofocus this field when true.
 *   - className?: string  Extra classes, appended last.
 *   - ...props            Spread onto the `<input>` (value, onChange, …).
 */
export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    // A ref is a mutable box that survives re-renders; here it points at the DOM
    // node so effects/handles can call methods on it.
    const localRef = useRef(null);

    // Publish only `focus()` on the forwarded ref. The `?.` guards the case where
    // the node is not mounted yet.
    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    // Run this after mount and whenever `isFocused` changes — the dependency array
    // `[isFocused]` tells React exactly when to re-run the effect.
    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={
                'w-full rounded-md bg-white/90 border border-gray-300 text-black placeholder-gray-500 px-3 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-300 ' +
                className
            }
            ref={localRef}
        />
    );
});
