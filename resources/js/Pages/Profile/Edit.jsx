import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import usePlatformBranding from '@/Utils/usePlatformBranding';

/**
 * Profile Manager - a universal module available to every role.
 *
 * Top section : a full-width identity summary card (avatar, name, roles, institution).
 * Bottom section: a two-column grid containing profile info, password form, and account deletion.
 *
 * Deliberately plain and consistent with the rest of the app design system.
 */
function RoleBadge({ role }) {
    const isGlobal = role === 'Software Super Admin';

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${
                isGlobal
                    ? 'border-amber-100 bg-amber-50 text-amber-700'
                    : 'border-slate-200 bg-slate-100 text-slate-600'
            }`}
        >
            {role}
        </span>
    );
}

export default function Edit({ mustVerifyEmail, status, profile = {}, avatarUrl = null }) {
    // Operator guardrail: when false, the SSA password form is replaced with the
    // CLI instructions (config/platform.php -> PasswordGuard).
    const { ssaSelfServicePassword } = usePlatformBranding();

    const initials = (profile.name || 'U')
        .trim()
        .split(/\s+/)
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Profile Manager</h2>
                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                        Manage your personal details, picture and credentials.
                    </p>
                </div>
            }
        >
            <Head title="Profile Manager" />

            <div className="space-y-6">
                {/* Full-width Identity Card */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-6 border-b border-slate-100 bg-slate-50/60 px-6 py-6">
                        <div className="flex items-center gap-4">
                            {avatarUrl ? (
                                <img
                                    src={avatarUrl}
                                    alt={profile.name}
                                    className="h-16 w-16 rounded-2xl object-cover"
                                />
                            ) : (
                                <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--accent)] text-2xl font-bold text-white">
                                    {initials}
                                </span>
                            )}
                            <div>
                                <div className="text-lg font-bold text-slate-900">{profile.name}</div>
                                <div className="text-xs text-slate-500">{profile.email}</div>
                                {profile.designation && (
                                    <div className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        {profile.designation}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {(profile.roles || []).length > 0 ? (
                                profile.roles.map((role) => <RoleBadge key={role} role={role} />)
                            ) : (
                                <span className="text-[11px] italic text-slate-400">No role assigned</span>
                            )}
                        </div>
                    </div>

                    <dl className="grid grid-cols-2 md:grid-cols-4 divide-x divide-y md:divide-y-0 divide-slate-100 text-sm bg-white">
                        <div className="px-6 py-4 flex flex-col justify-center">
                            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Institution</dt>
                            <dd className="mt-1 font-medium text-slate-700">
                                {profile.is_super_admin ? 'All institutions' : (profile.institution || '—')}
                            </dd>
                        </div>
                        <div className="px-6 py-4 flex flex-col justify-center">
                            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Status</dt>
                            <dd className="mt-1">
                                <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold ${
                                    (profile.status || 'active') === 'active'
                                        ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                        : 'border-slate-200 bg-slate-100 text-slate-500'
                                }`}>
                                    {(profile.status || 'active') === 'active' ? 'Active' : 'Inactive'}
                                </span>
                            </dd>
                        </div>
                        {profile.joined_at && (
                            <div className="px-6 py-4 flex flex-col justify-center">
                                <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Joined</dt>
                                <dd className="mt-1 font-medium text-slate-700">{profile.joined_at}</dd>
                            </div>
                        )}
                        {profile.password_changed_at && (
                            <div className="px-6 py-4 flex flex-col justify-center">
                                <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Password changed</dt>
                                <dd className="mt-1 font-medium text-slate-700">{profile.password_changed_at}</dd>
                            </div>
                        )}
                    </dl>
                </div>

                {/* Editable sections grid */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 lg:col-span-2">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                        />
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        {/*
                         * SSA CREDENTIAL GUARDRAIL (config/platform.php).
                         *
                         * Normally the SSA changes their password here like any
                         * user. When an operator sets
                         * PLATFORM_SSA_ALLOW_SELF_SERVICE_PASSWORD=false, the form
                         * is replaced with the CLI path so credentials can only
                         * move through the audited operator command.
                         */}
                        {profile?.is_super_admin && !ssaSelfServicePassword ? (
                            <div>
                                <h2 className="text-lg font-medium text-slate-900">Password</h2>
                                <p className="mt-1 text-sm text-slate-600">
                                    Super Admin credentials are locked to the operator path on this
                                    deployment. Ask your platform operator to rotate the password with:
                                </p>
                                <code className="mt-3 block rounded-lg bg-slate-900 px-4 py-3 text-xs text-slate-100">
                                    php artisan ssa:reset-password {profile?.email}
                                </code>
                            </div>
                        ) : (
                            <UpdatePasswordForm />
                        )}
                    </section>

                    <section className="rounded-2xl border border-rose-200 bg-white p-6 shadow-sm sm:p-8">
                        <DeleteUserForm />
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}