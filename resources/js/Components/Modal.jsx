import {
    Dialog,
    DialogPanel,
    Transition,
    TransitionChild,
} from '@headlessui/react';

/**
 * Modal — a centered dialog overlay built on Headless UI's <Dialog>.
 *
 * Parent-controlled: the parent owns the `show` boolean and passes `onClose` to
 * flip it back, so this component holds no open/closed state of its own. It
 * renders nothing visible while `show` is false (Headless UI removes it once the
 * leave transition ends).
 *
 * Props:
 *   - show?: boolean       Whether the dialog is visible. Defaults to false.
 *   - closeable?: boolean  When false, the backdrop/Esc no longer dismiss it —
 *                          useful for a blocking modal that must be answered.
 *   - maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl'  Panel width. Defaults to '2xl'.
 *   - onClose?: () => void Called when the user dismisses the dialog.
 *   - children             The dialog content (its header, body, buttons).
 */
export default function Modal({
    children,
    show = false,
    maxWidth = '2xl',
    closeable = true,
    onClose = () => {},
}) {
    // Single guarded close handler: it no-ops while `closeable` is false, so the
    // backdrop click and the Esc key (both wired to it) respect that setting.
    const close = () => {
        if (closeable) {
            onClose();
        }
    };

    // Translate the `maxWidth` prop into a Tailwind class via a lookup table; the
    // `[maxWidth]` at the end reads that map at the key. The `sm:` prefix means
    // the constraint applies from the `sm` breakpoint up (mobile stays full width).
    const maxWidthClass = {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[maxWidth];

    // Headless UI's <Transition> animates mount/unmount; `show` is the single
    // source of truth for visibility.
    return (
        <Transition show={show} leave="duration-200">
            <Dialog
                as="div"
                id="modal"
                className="fixed inset-0 z-50 flex transform items-center overflow-y-auto px-4 py-6 transition-all sm:px-0"
                onClose={close}
            >
                {/* Backdrop: a full-screen dim layer. Clicking it — or pressing
                    Esc, which Headless UI routes through `onClose` above — calls
                    the guarded `close`. */}
                <TransitionChild
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="absolute inset-0 bg-gray-500/75" />
                </TransitionChild>

                {/* The panel itself: on small screens it slides up from the bottom
                    (`translate-y-4`), while from the `sm:` breakpoint up it scales
                    in. `sm:` is a Tailwind responsive prefix (min-width: 640px). */}
                <TransitionChild
                    enter="ease-out duration-300"
                    enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enterTo="opacity-100 translate-y-0 sm:scale-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                    leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <DialogPanel
                        className={`mb-6 transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:mx-auto sm:w-full ${maxWidthClass}`}
                    >
                        {children}
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}
