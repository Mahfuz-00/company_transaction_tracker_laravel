import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

/**
 * Confirm-password gate — the screen behind Laravel's `password.confirm`
 * middleware.
 *
 * A plain React FUNCTION component: Inertia resolves it from
 * `Pages/Auth/ConfirmPassword.jsx` and renders it, so there is no router or
 * controller wiring in this file. It receives NO page props.
 *
 * WHY it exists: sensitive actions (deleting the account, managing roles, ...)
 * sit behind Laravel's "confirm your password first" middleware. A signed-in
 * user who has not re-entered their password recently is redirected here; on
 * success the server forwards them to the page they originally wanted (the
 * `url.intended` value), so this screen never needs to know the destination.
 *
 * Inertia / React concepts on show:
 *   - `useForm` is per-component form state (data, errors, processing) — the
 *     React counterpart of a view model.
 *   - `post(route('password.confirm'))` submits via fetch with NO full page
 *     reload; Inertia then follows the server's redirect.
 *   - `route()` is Ziggy: it turns the named Laravel route into a URL.
 *   - `errors.password` is the server's validation bag, keyed by field name, and
 *     rendered by <InputError>.
 */
export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        // Stop the browser's native full-page form POST — Inertia owns submission.
        e.preventDefault();

        post(route('password.confirm'), {
            // onFinish runs after the request settles, success OR failure; wiping
            // the password keeps the secret out of state once it is spent.
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Confirm Password" />

            <div className="mb-4 text-sm text-gray-600">
                This is a secure area of the application. Please confirm your
                password before continuing.
            </div>

            <form onSubmit={submit}>
                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Password" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        isFocused={true}
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton className="ms-4" disabled={processing}>
                        Confirm
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
