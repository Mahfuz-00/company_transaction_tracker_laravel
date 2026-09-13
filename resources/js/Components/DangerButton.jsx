export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}) {
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
