import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

/**
 * Danger-zone card: permanently delete the signed-in user's account.
 *
 * Not a page — a PARTIAL: a self-contained form component rendered inside
 * Profile/Edit. It owns its own confirmation modal, its own form state and its
 * own request, so the parent page stays a thin layout.
 *
 * PROPS
 *   - `className` : optional extra classes appended to the root <section>, so the
 *     parent controls spacing/width.
 *
 * Inertia / React concepts on show:
 *   - `useForm` exposes `delete` here (aliased to `destroy`) because the request
 *     is a DELETE, not a POST.
 *   - `useRef` points at the password input so an error can focus it directly —
 *     the React equivalent of requesting focus on a native view.
 *   - `preserveScroll` keeps the page where it is during the Inertia visit.
 *   - `clearErrors()` wipes the server error bag when the modal is dismissed.
 */
export default function DeleteUserForm({ className = '' }) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        // `delete` is aliased to `destroy` so call sites read as a verb and do not
        // shadow the JS `delete` keyword.
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            // Keep the viewport steady while the request is in flight.
            preserveScroll: true,
            onSuccess: () => closeModal(),
            // Validation failed (wrong password): move focus straight to the field
            // so the user can correct it without hunting for it.
            onError: () => passwordInput.current.focus(),
            // Always clear the field afterwards.
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        // Drop both the field value and any server errors so reopening the modal
        // starts from a clean slate.
        clearErrors();
        reset();
    };

    return (
        <section className={`space-y-6 ${className}`}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Delete Account
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Once your account is deleted, all of its resources and data
                    will be permanently deleted. Before deleting your account,
                    please download any data or information that you wish to
                    retain.
                </p>
            </header>

            <DangerButton onClick={confirmUserDeletion}>
                Delete Account
            </DangerButton>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Are you sure you want to delete your account?
                    </h2>

                    <p className="mt-1 text-sm text-gray-600">
                        Once your account is deleted, all of its resources and
                        data will be permanently deleted. Please enter your
                        password to confirm you would like to permanently delete
                        your account.
                    </p>

                    <div className="mt-6">
                        <InputLabel
                            htmlFor="password"
                            value="Password"
                            className="sr-only"
                        />

                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) =>
                                setData('password', e.target.value)
                            }
                            className="mt-1 block w-3/4"
                            isFocused
                            placeholder="Password"
                        />

                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>
                            Cancel
                        </SecondaryButton>

                        <DangerButton className="ms-3" disabled={processing}>
                            Delete Account
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
