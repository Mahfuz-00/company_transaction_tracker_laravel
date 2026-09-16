import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Spinner } from '@/Components/UI/Loading';

/**
 * The member lands here from the invitation email. They choose their own
 * password - no default password ever exists in the system.
 */
export default function AcceptInvitation({ invitation, token }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token || '',
        name: invitation?.name || '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('invitations.complete', invitation.id), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Complete your account" />

            <div className="mb-6">
                <h2 className="text-xl font-bold text-slate-900">Set your password</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Welcome{invitation?.name ? `, ${invitation.name}` : ''}. Choose a password to
                    activate <strong>{invitation?.email}</strong>.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="name" value="Your name" />
                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1 block w-full"
                        autoComplete="name"
                        isFocused
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Password" />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirm password" />
                    <TextInput
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-1 block w-full"
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        required
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <div className="flex items-center justify-between pt-2">
                    <Link href={route('login')} className="text-sm text-slate-500 underline hover:text-slate-700">
                        Already have an account?
                    </Link>

                    <PrimaryButton disabled={processing} className="inline-flex items-center gap-2">
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Saving...' : 'Activate account'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
