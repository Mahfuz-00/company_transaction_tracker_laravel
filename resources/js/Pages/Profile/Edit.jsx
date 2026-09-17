import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

/**
 * Profile Manager - a universal module available to every role.
 *
 * Left column : an identity card (avatar, name, roles, institution).
 * Right column: the editable profile info, the password form, and (last) the
 *               danger-zone account deletion.
 *
 * Deliberately plain and consistent with the rest of the app design system.
 */
function RoleBadge({ role }) {
    const isGlobal = role === 'Software Super Admin';

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${isGlobal
                ? 'border-amber-100 bg-amber-50 text-amber-700'
                : 'border-slate-200 bg-slate-100 text-slate-600'
                }`}
        >
            {role}
        </span>
    );
}

export default function Edit({ mustVerifyEmail, status, profile = {}, avatarUrl = null }) {
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

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">
                {/* Identity card */}
                <aside className="lg:col-span-4 lg:sticky lg:top-6">
                    <div className="overflow-hidden rounded-2xl border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col items-center gap-3 border-b border-slate-100 bg-slate-50/60 px-6 py-8">
                            {avatarUrl ? (
                                <img
                                    src={avatarUrl}
                                    alt={profile.name}
                                    className="h-20 w-20 rounded-2xl object-cover"
                                />
                            ) : (
                                <span className="flex h-20 w-20 items-center justify-center rounded-2xl bg-[var(--accent)] text-2xl font-bold text-white">
                                    {initials}
                                </span>
                            )}
                            <div className="text-center">
                                <div className="text-lg font-bold text-slate-900">{profile.name}</div>
                                <div className="text-xs text-slate-500">{profile.email}</div>
                                {profile.designation && (
                                    <div className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        {profile.designation}
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap justify-center gap-1.5">
                                {(profile.roles || []).length > 0 ? (
                                    profile.roles.map((role) => <RoleBadge key={role} role={role} />)
                                ) : (
                                    <span className="text-[11px] italic text-slate-400">No role assigned</span>
                                )}
                            </div>
                        </div>

                        <dl className="divide-y divide-slate-100 text-sm">
                            <div className="flex items-center justify-between px-6 py-3">
                                <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Institution</dt>
                                <dd className="font-medium text-slate-700">
                                    {profile.is_super_admin ? 'All institutions' : (profile.institution || '—')}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between px-6 py-3">
                                <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Status</dt>
                                <dd>
                                    <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold ${(profile.status || 'active') === 'active'
                                        ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                        : 'border-slate-200 bg-slate-100 text-slate-500'
                                        }`}>
                                        {(profile.status || 'active') === 'active' ? 'Active' : 'Inactive'}
                                    </span>
                                </dd>
                            </div>
                            {profile.joined_at && (
                                <div className="flex items-center justify-between px-6 py-3">
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Joined</dt>
                                    <dd className="font-medium text-slate-700">{profile.joined_at}</dd>
                                </div>
                            )}
                            {profile.password_changed_at && (
                                <div className="flex items-center justify-between px-6 py-3">
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-slate-400">Password changed</dt>
                                    <dd className="font-medium text-slate-700">{profile.password_changed_at}</dd>
                                </div>
                            )}
                        </dl>
                    </div>
                </aside>

                {/* Editable sections */}
                <div className="space-y-6 lg:col-span-8">
                    <section className="rounded-2xl border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                        />
                    </section>

                    <section className="rounded-2xl border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <UpdatePasswordForm />
                    </section>

                    <section className="rounded-2xl border-rose-200 bg-white p-6 shadow-sm sm:p-8">
                        <DeleteUserForm />
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
