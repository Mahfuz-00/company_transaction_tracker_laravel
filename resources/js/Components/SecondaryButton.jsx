export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}) {
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
