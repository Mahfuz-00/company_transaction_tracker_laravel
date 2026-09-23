import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Spinner } from '@/Components/UI/Loading';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Partial: the "Profile Information" card on the Profile page.
 *
 * A self-contained form component (not a page) rendered inside Profile/Edit. It
 * edits the current user's name, email and profile picture.
 *
 * PROPS
 *   - `mustVerifyEmail` : when true, email verification is required; combined
 *     with the account's `email_verified_at` it decides whether the "unverified"
 *     reminder (with a resend link) appears.
 *   - `status`          : server flash; `'verification-link-sent'` confirms a
 *     fresh verification email was dispatched.
 *   - `className`       : optional classes for the root <section>.
 *
 * Inertia / React concepts on show:
 *   - `usePage().props.auth.user` reads SHARED props (the signed-in user) from any
 *     component without prop-drilling.
 *   - `useForm` is seeded FROM that user, so the initial state mirrors the server.
 *   - the submit uses `post()` with `_method: 'patch'` (method spoofing) — see the
 *     inline note for why a real PATCH cannot carry the avatar file.
 *   - a local blob preview (`URL.createObjectURL`) shows the chosen image before
 *     the upload finishes.
 */
export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;

    const { data, setData, post, errors, processing, recentlySuccessful } =
        useForm({
            // Method spoofing: see submit() for why this is POST rather than PATCH.
            _method: 'patch',
            name: user.name,
            email: user.email,
            avatar: null,
            remove_avatar: false,
        });

    // Preview the chosen image straight away, before the upload round-trip.
    const [avatarPreview, setAvatarPreview] = useState(user.avatar_url || null);

    const onAvatarChange = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setData('avatar', file);
        setData('remove_avatar', false);
        setAvatarPreview(URL.createObjectURL(file));
    };

    const submit = (e) => {
        e.preventDefault();

        /*
         * WHY post() + _method instead of patch():
         *
         * The avatar rides along as a file, so the request must be
         * multipart/form-data. PHP only populates the request body for POST -
         * it does NOT parse a multipart payload on PATCH/PUT. Sending a real
         * PATCH therefore delivered an EMPTY payload, and every field failed
         * "is required" even with valid input (the reported validation bug).
         *
         * POSTing with _method=patch keeps the multipart body intact AND still
         * routes to the PATCH handler in Laravel.
         */
        post(route('profile.update'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                // Once saved, drop the local blob preview so the freshly-versioned
                // server URL (avatar_url) is what renders next.
                setAvatarPreview(null);
            },
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Profile Information
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Update your account's profile information and email address.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                {/* Profile picture */}
                <div>
                    <InputLabel value="Profile picture" />
                    <div className="mt-2 flex items-center gap-4">
                        {avatarPreview ? (
                            <img
                                src={avatarPreview}
                                alt="Profile picture"
                                className="h-16 w-16 flex-shrink-0 rounded-full object-cover"
                            />
                        ) : (
                            <span className="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full bg-[var(--accent-soft)] text-lg font-bold text-[var(--accent)]">
                                {user.name.slice(0, 2).toUpperCase()}
                            </span>
                        )}

                        <div className="flex flex-col gap-2">
                            <input
                                id="avatar"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={onAvatarChange}
                                className="block text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--accent)] file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:opacity-90"
                            />
                            {avatarPreview && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setAvatarPreview(null);
                                        setData('avatar', null);
                                        setData('remove_avatar', true);
                                    }}
                                    className="w-fit text-xs font-semibold text-rose-500 hover:text-rose-700"
                                >
                                    Remove picture
                                </button>
                            )}
                        </div>
                    </div>
                    <InputError className="mt-2" message={errors.avatar} />
                </div>

                <div>
                    <InputLabel htmlFor="name" value="Name" />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.email} />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-gray-800">
                            Your email address is unverified.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-green-600">
                                A new verification link has been sent to your
                                email address.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing} className="inline-flex items-center gap-2">
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Saving...' : 'Save'}
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
