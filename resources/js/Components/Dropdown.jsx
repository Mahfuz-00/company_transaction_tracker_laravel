import { Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { createContext, useContext, useState } from 'react';

/**
 * Dropdown — a small "compound component" for menus (e.g. a profile menu).
 *
 * Rather than one big component with many props, it exposes composable parts the
 * caller arranges itself:
 *
 *     <Dropdown>
 *         <Dropdown.Trigger><button>Open</button></Dropdown.Trigger>
 *         <Dropdown.Content>
 *             <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
 *         </Dropdown.Content>
 *     </Dropdown>
 *
 * The parts share open/closed state through React Context instead of prop
 * drilling: the root owns the state and the children read it with `useContext`.
 * This is the React analogue of a Flutter InheritedWidget scoped to one subtree.
 */
const DropDownContext = createContext();

/** Root: owns the open/closed state and shares it via Context. */
const Dropdown = ({ children }) => {
    // `useState` gives the component memory that survives re-renders; setting it
    // re-renders this subtree with the new value.
    const [open, setOpen] = useState(false);

    // Use the functional updater (`prev => !prev`) so rapid toggles never act on a
    // stale captured value.
    const toggleOpen = () => {
        setOpen((previousState) => !previousState);
    };

    return (
        // The Provider makes { open, setOpen, toggleOpen } available to every
        // descendant that calls useContext(DropDownContext).
        <DropDownContext.Provider value={{ open, setOpen, toggleOpen }}>
            {/* `relative` anchors the absolutely-positioned Content panel. */}
            <div className="relative">{children}</div>
        </DropDownContext.Provider>
    );
};

/** Trigger: the clickable element; clicking it toggles the menu open/closed. */
const Trigger = ({ children }) => {
    // Read the shared state out of Context (provided by the root above).
    const { open, setOpen, toggleOpen } = useContext(DropDownContext);

    // The fragment `<>…</>` groups sibling nodes WITHOUT adding a wrapper element
    // to the DOM — handy when a component must return more than one node.
    return (
        <>
            <div onClick={toggleOpen}>{children}</div>

            {/* While open, this invisible full-screen layer sits under the menu and
                closes it on any outside click ("click-away"). `{open && …}` is the
                usual React shorthand for "render only when open" — a falsy
                condition renders nothing. */}
            {open && (
                <div
                    className="fixed inset-0 z-40"
                    onClick={() => setOpen(false)}
                ></div>
            )}
        </>
    );
};

/**
 * Content: the floating menu panel. Must sit inside the Dropdown root, since it
 * reads the shared `open` state from Context.
 *
 * Props:
 *   - align?: 'left' | 'right'   Which edge the panel hugs (default 'right').
 *   - width?: string             Width token; only '48' is mapped (→ `w-48`).
 *   - contentClasses?: string    Surface styles for the inner panel.
 *   - children                   The menu items (usually <Dropdown.Link>).
 */
const Content = ({
    align = 'right',
    width = '48',
    contentClasses = 'py-1 bg-white/90 text-black',
    children,
}) => {
    const { open, setOpen } = useContext(DropDownContext);

    // Map the `align` prop to Tailwind classes. `ltr:`/`rtl:` are writing-direction
    // variants, so the panel mirrors correctly in RTL locales.
    let alignmentClasses = 'origin-top';

    if (align === 'left') {
        alignmentClasses = 'ltr:origin-top-left rtl:origin-top-right start-0';
    } else if (align === 'right') {
        alignmentClasses = 'ltr:origin-top-right rtl:origin-top-left end-0';
    }

    // Only the known width token is translated to a class; anything else falls
    // back to the panel's natural width.
    let widthClasses = '';

    if (width === '48') {
        widthClasses = 'w-48';
    }

    // Headless UI animates the panel in and out; `show={open}` ties it to the
    // shared state, so there is no manual conditional rendering here.
    return (
        <>
            <Transition
                show={open}
                enter="transition ease-out duration-200"
                enterFrom="opacity-0 scale-95"
                enterTo="opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="opacity-100 scale-100"
                leaveTo="opacity-0 scale-95"
            >
                <div
                    className={`absolute z-50 mt-2 rounded-md shadow-lg ${alignmentClasses} ${widthClasses}`}
                    onClick={() => setOpen(false)}
                >
                    <div className={`rounded-md ring-1 ring-white/10 ${contentClasses}`}>
                        {children}
                    </div>
                </div>
            </Transition>
        </>
    );
};

/** DropdownLink: a menu item that navigates via Inertia (no full page reload). */
const DropdownLink = ({ className = '', children, ...props }) => {
    // Inertia's <Link> intercepts the click and performs a client-side visit, so
    // `href={route('…')}` swaps the page component in place of reloading.
    return (
        <Link
            {...props}
            className={`block w-full px-4 py-2 text-start text-sm leading-5 text-black transition duration-150 ease-in-out hover:bg-white/90 focus:bg-white/90 focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
};

// Attach the sub-components as static properties (Dropdown.Trigger, …). This is
// what makes the compound-component API work: they ship as one importable unit
// while still being composed independently by the caller.
Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;

export default Dropdown;
