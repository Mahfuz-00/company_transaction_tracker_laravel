import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

/**
 * "Verify email" screen — the holding page for unverified accounts.
 *
 * Laravel's `verified` middleware redirects here whenever a signed-in user has
 * not clicked the verification link yet. The screen does two things and nothing
 * else: resend the verification email, or log out.
 *
 * PROPS
 *   - `status` : a flash string. When it equals `'verification-link-sent'`,
 *     Laravel confirms a fresh mail went out and we show the green note.
 *
 * Inertia / React concepts on show:
 *   - `useForm({})` uses an EMPTY payload: we only need `post` and `processing`,
 *     because the form submits no fields — the server already knows who is
 *     signed in from the session.
 *   - `<Link method="post" as="button">` is how Inertia issues a POST from
 *     something that must look like a link (used for "Log Out").
 */
export default function VerifyEmail({ status }) {
    // Empty payload: only `post` and `processing` are needed — the resend endpoint
    // identifies the user from the session, so no fields are submitted.
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Email Verification" />

            <div className="mb-4 text-sm text-gray-600">
                Thanks for signing up! Before getting started, could you verify
                your email address by clicking on the link we just emailed to
                you? If you didn't receive the email, we will gladly send you
                another.
            </div>

            {/* The server sends exactly this string once a resend succeeds. */}
            {status === 'verification-link-sent' && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    A new verification link has been sent to the email address
                    you provided during registration.
                </div>
            )}

            <form onSubmit={submit}>
                <div className="mt-4 flex items-center justify-between">
                    <PrimaryButton disabled={processing}>
                        Resend Verification Email
                    </PrimaryButton>

                    {/* Inertia POST that renders as a link: logout changes server
                        state, so it cannot be a plain GET navigation. */}
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Log Out
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
