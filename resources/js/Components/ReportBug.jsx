import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * REPORT BUG — the in-app defect reporter.
 *
 * WHO SEES IT
 *   Every role EXCEPT the Software Super Admin. The SSA is the RECIPIENT of these
 *   reports (see BugReportController), so offering them the button would be
 *   circular. `AuthenticatedLayout` decides whether to mount this, and the server
 *   re-asserts the rule — the button being hidden is a convenience, not the
 *   security boundary.
 *
 * WHY IT CAPTURES page_url AUTOMATICALLY
 *   "It's broken somewhere in the meals area" is unactionable. The exact path is
 *   the single most useful field for reproduction, and a user will not type it
 *   accurately — so the component reads `window.location` at submit time and sends
 *   it without the user having to think about it.
 *
 * THE SCREENSHOT
 *   Optional but encouraged: for a UI defect it removes several round-trips.
 *   Validated server-side (image, 5 MB) — this client-side `accept`/hint is
 *   guidance, not enforcement.
 *
 * @param {object}   props
 * @param {boolean}  props.show      - whether the modal is open
 * @param {Function} props.onClose   - close handler (owned by the parent)
 */
export default function ReportBug({ show, onClose }) {
    const fileInput = useRef(null);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        title: '',
        description: '',
        steps: '',
        severity: 'normal',
        page_url: '',
        screenshot: null,
    });

    // Stamp the CURRENT page each time the modal opens, so a report filed after
    // navigating still names the screen the user was looking at.
    useEffect(() => {
        if (show) {
            setData(
                'page_url',
                typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/'
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);

    const submit = (e) => {
        e.preventDefault();

        // `forceFormData` is required: the screenshot makes this a multipart
        // upload, and Inertia must not JSON-encode it.
        post(route('bug-reports.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                close();
            },
        });
    };

    const close = () => {
        reset();
        clearErrors();
        if (fileInput.current) fileInput.current.value = '';
        onClose();
    };

    const inputClass =
        'mt-1 block w-full rounded-xl border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-800 shadow-sm transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 placeholder:text-slate-400';

    return (
        <Modal show={show} onClose={close} maxWidth="lg">
            <form onSubmit={submit} data-testid="report-bug-form" className="p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900">Report a bug</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Tell us what went wrong. Your report goes straight to the platform team.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={close}
                        aria-label="Close"
                        className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="mt-5 space-y-4">
                    {/* Title */}
                    <div>
                        <InputLabel htmlFor="bug_title" value="What went wrong?" className="text-[11px] font-bold uppercase tracking-wider text-slate-400" />
                        <TextInput
                            id="bug_title"
                            value={data.title}
                            className={inputClass}
                            placeholder="e.g. Deposit total is wrong on the dashboard"
                            onChange={(e) => setData('title', e.target.value)}
                            required
                            autoFocus
                        />
                        <InputError message={errors.title} className="mt-1.5 text-xs font-medium text-rose-600" />
                    </div>

                    {/* Description */}
                    <div>
                        <InputLabel htmlFor="bug_description" value="Describe the problem" className="text-[11px] font-bold uppercase tracking-wider text-slate-400" />
                        <textarea
                            id="bug_description"
                            rows={4}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className={inputClass}
                            placeholder="What did you expect to happen, and what happened instead?"
                            required
                        />
                        <InputError message={errors.description} className="mt-1.5 text-xs font-medium text-rose-600" />
                    </div>

                    {/* Steps */}
                    <div>
                        <InputLabel htmlFor="bug_steps" value="Steps to reproduce (optional)" className="text-[11px] font-bold uppercase tracking-wider text-slate-400" />
                        <textarea
                            id="bug_steps"
                            rows={3}
                            value={data.steps}
                            onChange={(e) => setData('steps', e.target.value)}
                            className={inputClass}
                            placeholder={'1. Open Deposits\n2. Filter by September\n3. The total is 0'}
                        />
                        <InputError message={errors.steps} className="mt-1.5 text-xs font-medium text-rose-600" />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {/* Severity */}
                        <div>
                            <InputLabel htmlFor="bug_severity" value="How bad is it?" className="text-[11px] font-bold uppercase tracking-wider text-slate-400" />
                            <select
                                id="bug_severity"
                                value={data.severity}
                                onChange={(e) => setData('severity', e.target.value)}
                                className={inputClass}
                            >
                                <option value="low">Low — cosmetic</option>
                                <option value="normal">Normal — annoying but usable</option>
                                <option value="high">High — blocking my work</option>
                                <option value="critical">Critical — data loss / cannot log in</option>
                            </select>
                            <InputError message={errors.severity} className="mt-1.5 text-xs font-medium text-rose-600" />
                        </div>

                        {/* Screenshot */}
                        <div>
                            <InputLabel htmlFor="bug_screenshot" value="Screenshot (optional)" className="text-[11px] font-bold uppercase tracking-wider text-slate-400" />
                            <input
                                id="bug_screenshot"
                                ref={fileInput}
                                type="file"
                                accept="image/*"
                                onChange={(e) => setData('screenshot', e.target.files?.[0] ?? null)}
                                className="mt-1 block w-full cursor-pointer rounded-xl border border-slate-200 bg-white text-xs text-slate-600 file:mr-3 file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-slate-100 file:px-3 file:py-2.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200"
                            />
                            <p className="mt-1 text-[11px] text-slate-400">PNG or JPG, up to 5 MB.</p>
                            <InputError message={errors.screenshot} className="mt-1.5 text-xs font-medium text-rose-600" />
                        </div>
                    </div>

                    {/* The captured page, shown so the user knows what we recorded. */}
                    {data.page_url && (
                        <p className="text-[11px] text-slate-400">
                            Page: <code className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">{data.page_url}</code>
                        </p>
                    )}
                </div>

                <div className="mt-6 flex items-center justify-end gap-3">
                    <SecondaryButton type="button" onClick={close} className="rounded-xl px-4 py-2.5 text-sm font-semibold">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/25 transition-all hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {processing ? 'Sending…' : 'Send report'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}