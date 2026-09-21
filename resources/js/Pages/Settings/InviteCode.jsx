import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, router, usePage } from '@inertiajs/react';
import Card from '@/Components/UI/Card';
import Button from '@/Components/UI/Button';
import ThemedText from '@/Components/UI/ThemedText';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';

/**
 * Institution Invite Code Manager.
 *
 * Shows the workspace's invite code - the key a member types on the public
 * signup form - plus a ready-to-share link. An admin can copy either, or rotate
 * the code (which revokes any previously shared link).
 *
 * The value and the register URL come straight from InviteCodeController::show.
 */
export default function InviteCode({ institution, inviteCode, registerUrl, canManage = false, memberCount = 0 }) {
    const { flash } = usePage().props;
    const { confirm } = useFeedback();
    const [copied, setCopied] = useState(null);

    const copy = async (value, which) => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(which);
            setTimeout(() => setCopied(null), 2000);
        } catch (e) {
            // Clipboard may be blocked; the value is still selectable on screen.
        }
    };

    const regenerate = async () => {
        const ok = await confirm({
            title: 'Regenerate the invite code?',
            message: 'The current code will stop working immediately. Anyone still using it must be given the new code.',
            tone: 'warning',
            confirmLabel: 'Regenerate',
        });
        if (!ok) return;

        router.post(route('settings.invite-code.regenerate'), {}, { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Invite Code" />

            <div className="space-y-6">
                <div>
                    <h3 className="text-lg font-bold text-slate-900">Invite Code</h3>
                    <p className="mt-0.5 text-sm text-slate-500">
                        Share this code with people who should join{' '}
                        <strong className="font-semibold text-slate-700">{institution?.name}</strong>. They
                        enter it on the sign-up page to be placed in the right workspace.
                    </p>
                </div>

                {flash?.success && (
                    <div role="status" className="rounded-xl border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="status" className="rounded-xl border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                        {flash.error}
                    </div>
                )}

                <Card className="p-6 sm:p-7 border-slate-200/80 shadow-xs rounded-2xl bg-white space-y-6">
                    <div>
                        <ThemedText as="div" variant="overline">Your invite code</ThemedText>
                        <div className="mt-2 flex-wrap items-center gap-3">
                            <code
                                data-testid="invite-code"
                                className="rounded-xl border-indigo-100 bg-indigo-50 px-4 py-2.5 text-lg font-bold tracking-[0.2em] text-indigo-700"
                            >
                                {inviteCode}
                            </code>
                            <Button
                                type="button"
                                onClick={() => copy(inviteCode, 'code')}
                                className="inline-flex items-center gap-2 rounded-xl border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                {copied === 'code' ? 'Copied!' : 'Copy code'}
                            </Button>
                        </div>
                    </div>

                    <div className="border-t border-slate-100 pt-6">
                        <ThemedText as="div" variant="overline">Shareable sign-up link</ThemedText>
                        <div className="mt-2 flex-wrap items-center gap-3">
                            <code
                                data-testid="invite-link"
                                className="max-w-full overflow-x-auto rounded-xl border-slate-200 bg-slate-50 px-3.5 py-2.5 text-xs font-medium text-slate-700"
                            >
                                {registerUrl}
                            </code>
                            <Button
                                type="button"
                                onClick={() => copy(registerUrl, 'link')}
                                className="inline-flex items-center gap-2 rounded-xl border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                {copied === 'link' ? 'Copied!' : 'Copy link'}
                            </Button>
                        </div>
                        <p className="mt-2 text-[11px] text-slate-400">
                            Anyone with this link can register into your workspace.
                        </p>
                    </div>

                    <div className="rounded-xl border-slate-200 bg-slate-50 p-4 text-xs leading-relaxed text-slate-600">
                        <strong className="font-semibold text-slate-700">{memberCount}</strong> member(s)
                        currently belong to this workspace. A self-signup can only ever join as a Member (or
                        Meal Manager) - never as an admin.
                    </div>

                    {canManage && (
                        <div className="flex justify-end border-t border-slate-100 pt-6">
                            <Button
                                type="button"
                                onClick={regenerate}
                                className="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-rose-700"
                            >
                                Regenerate code
                            </Button>
                        </div>
                    )}
                </Card>
            </div>
        </SettingsLayout>
    );
}
