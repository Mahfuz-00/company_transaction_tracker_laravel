import React, { useEffect } from 'react';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import { useForm } from '@inertiajs/react';
import useTerminology from '@/Utils/useTerminology';

/**
 * Member / participant create-edit form, extracted from the (once 760-line)
 * Students page. Self-contained: it owns its Inertia form state and submits to
 * the correct endpoint based on `editing`.
 */

const EMPTY_FORM = {
    user_id: '',
    manager_id: '',
    name: '',
    roll: '',
    department_id: '',
    join_date: '',
    status: 'active',
};

export default function MemberFormModal({
    open,
    onClose,
    editing = null,
    departments = [],
    managers = [],
}) {
    const { t } = useTerminology();
    const isEditing = Boolean(editing);
    const memberWord = t('member', 'Member');

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...EMPTY_FORM });

    useEffect(() => {
        if (!open) return;

        clearErrors();

        if (editing) {
            setData({
                user_id: editing.user_id ? String(editing.user_id) : '',
                manager_id: editing.manager_id ? String(editing.manager_id) : '',
                name: editing.name || '',
                roll: editing.roll || '',
                department_id: editing.department_id ? String(editing.department_id) : '',
                join_date: editing.join_date ? String(editing.join_date).slice(0, 10) : '',
                status: editing.status || 'active',
            });
        } else {
            reset();
            setData({ ...EMPTY_FORM });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id]);

    const departmentOptions = [
        { value: '', label: `— No ${t('department', 'group').toLowerCase()} —` },
        ...departments.map((d) => ({ value: String(d.id), label: d.name })),
    ];

    // Manager options: staff who can oversee this member, plus the current one.
    const managerOptions = (() => {
        const list = managers.map((m) => ({
            value: String(m.id),
            label: `${m.name}${m.email ? ` (${m.email})` : ''}`,
        }));

        if (editing?.manager_id && !list.some((o) => o.value === String(editing.manager_id))) {
            list.unshift({ value: String(editing.manager_id), label: editing.manager_name || 'Current manager' });
        }

        return [{ value: '', label: '— Unassigned —' }, ...list];
    })();

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (isEditing) {
            put(route('meals.students.update', editing.id), options);
        } else {
            post(route('meals.students.store'), options);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={isEditing ? `Edit ${editing?.name}` : `Add ${memberWord}`}
            description={isEditing ? `Update this ${memberWord.toLowerCase()}'s profile.` : 'Add someone to the roster.'}
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="member-form"
                        disabled={processing}
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                    >
                        {processing && (
                            <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                            </svg>
                        )}
                        {processing ? 'Saving...' : isEditing ? 'Save Changes' : `Add ${memberWord}`}
                    </button>
                </>
            }
        >
            <form id="member-form" onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Full Name"
                        name="name"
                        required
                        value={data.name}
                        error={errors.name}
                        placeholder="e.g. Farhan Hossain"
                        onChange={(event) => setData('name', event.target.value)}
                    />

                    <Field
                        label="Roll ID"
                        name="roll"
                        value={data.roll}
                        error={errors.roll}
                        placeholder="e.g. CS-2021-045"
                        hint="Shown everywhere instead of the internal ID."
                        onChange={(event) => setData('roll', event.target.value)}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label={t('department', 'Group')}
                        name="department_id"
                        type="select"
                        value={data.department_id}
                        error={errors.department_id}
                        options={departmentOptions}
                        onChange={(event) => setData('department_id', event.target.value)}
                    />

                    <Field
                        label="Join Date"
                        name="join_date"
                        type="date"
                        value={data.join_date}
                        error={errors.join_date}
                        onChange={(event) => setData('join_date', event.target.value)}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Status"
                        name="status"
                        type="select"
                        required
                        value={data.status}
                        error={errors.status}
                        options={[
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                        ]}
                        onChange={(event) => setData('status', event.target.value)}
                    />

                    <Field
                        label="Managed By"
                        name="manager_id"
                        type="select"
                        value={data.manager_id}
                        error={errors.manager_id}
                        hint="The meal manager or admin who oversees this member."
                        options={managerOptions}
                        onChange={(event) => setData('manager_id', event.target.value)}
                    />
                </div>

                <div className="rounded-lg border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                    <strong className="font-semibold text-slate-700">No password is set here.</strong>{' '}
                    After saving, use <em>Invite</em> on the row to email a secure link so the
                    member chooses their own password and completes their account.
                </div>
            </form>
        </Modal>
    );
}
