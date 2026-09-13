export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
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
