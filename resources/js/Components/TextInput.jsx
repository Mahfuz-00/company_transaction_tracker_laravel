import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

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
