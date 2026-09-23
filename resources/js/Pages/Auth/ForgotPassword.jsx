import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * "Forgot password" screen — step 1 of the reset flow.
 *
 * Inertia page component. The user enters their email and POSTs it to
 * `password.email`, which asks Laravel to mail a signed reset link. That link
 * points at the ResetPassword page (step 2).
 *
 * PROPS
 *   - `status` : a one-off flash string from the server. Laravel sets it once a
 *     reset link has been sent ("We have emailed your password reset link!").
 *     It is the only feedback that the request worked, because a known and an
 *     unknown email are answered identically (to avoid leaking which addresses
 *     exist).
 *
 * Inertia / React concepts on show:
 *   - `useForm` owns the single `email` field plus its error and processing
 *     flags.
 *   - `post(route('password.email'))` is an Inertia visit, not a page reload.
 *   - `errors.email` renders the server-side validation message for that field.
 */
export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        // Inertia handles the request; suppress the browser's default submit.
        e.preventDefault();

        // No onSuccess handling here: the endpoint just mails the link and the
        // server flashes `status` back (handled above).
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Forgot Password" />

            <div className="mb-4 text-sm text-gray-600">
                Forgot your password? No problem. Just let us know your email
                address and we will email you a password reset link that will
                allow you to choose a new one.
            </div>

            {/* Server flash: the reset link was queued (or already sent). */}
            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <TextInput
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1 block w-full"
                    isFocused={true}
                    onChange={(e) => setData('email', e.target.value)}
                />

                <InputError message={errors.email} className="mt-2" />

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton className="ms-4" disabled={processing}>
                        Email Password Reset Link
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
