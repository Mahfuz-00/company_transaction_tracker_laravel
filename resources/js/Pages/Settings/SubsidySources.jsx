import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import { Spinner } from '@/Components/UI/Loading';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * Admin management of subsidy funding sources.
 *
 * Each institution funds its meals differently, so sources are editable rows
 * rather than a fixed list. Each carries a default percentage - the share of
 * the funding pool it is expected to carry - which feeds the analytics'
 * target-vs-actual comparison.
 */
export default function SubsidySources({ sources, totalPercentage }) {
    const { can } = useCan();
    const { flash } = usePage().props;
    const canManage = can('subsidies.manage');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        name: '',
        key: '',
        percentage: '',
        description: '',
        is_active: true,
    });

    const openCreate = () => {
        clearErrors();
        reset();
        setData({ name: '', key: '', percentage: '', description: '', is_active: true });
        setEditing(null);
        setModalOpen(true);
    };

    const openEdit = (source) => {
        clearErrors();
        setEditing(source);
        setData({
            name: source.name || '',
            key: source.key || '',
            percentage: source.percentage ?? '',
            description: source.description || '',
            is_active: source.is_active,
        });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditing(null);
        reset();
    };

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => closeModal() };

        if (editing) {
            put(route('settings.subsidy-sources.update', editing.id), options);
        } else {
            post(route('settings.subsidy-sources.store'), options);
        }
    };

    const destroy = (source) => {
        router.delete(route('settings.subsidy-sources.destroy', source.id), {
            preserveScroll: true,
            onFinish: () => setConfirmDelete(null),
        });
    };

    // A healthy split sums to ~100%. Anything else is worth flagging.
    const splitOff = Math.abs((totalPercentage || 0) - 100) > 0.5;

    return (
        <SettingsLayout title="Settings">
            <Head title="Subsidy Funding Sources" />

            <div className="space-y-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">Subsidy Funding Sources</h3>
                        <p className="mt-0.5 text-sm text-slate-500">
                            Where institutional subsidy money comes from, and the share each source
                            is expected to carry.
                        </p>
                    </div>

                    {canManage && (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:opacity-90"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add Source
                        </button>
                    )}
                </div>

                {flash?.success && (
                    <div role="status" className="rounded-lg border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="status" className="rounded-lg border-rose-200 bg-rose-50 p-3 text-sm font-medium text-rose-700">
                        {flash.error}
                    </div>
                )}

                {/* Default split summary */}
                <div className={`rounded-xl border p-4 shadow-sm ${splitOff ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}>
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                Total Default Share
                            </div>
                            <div className={`mt-0.5 text-2xl font-bold ${splitOff ? 'text-amber-600' : 'text-emerald-600'}`}>
                                {totalPercentage}%
                            </div>
                        </div>
                        <p className="max-w-sm text-right text-xs text-slate-500">
                            {splitOff
                                ? 'The active sources do not add up to 100%. Adjust the percentages so the split matches your funding agreement.'
                                : 'The active sources add up to a full 100% split.'}
                        </p>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-140 border-collapse text-left">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3">Source</th>
                                    <th className="px-6 py-3">Key</th>
                                    <th className="px-6 py-3 text-right">Default Share</th>
                                    <th className="px-6 py-3 text-right">Used In</th>
                                    <th className="px-6 py-3">Status</th>
                                    {canManage && <th className="px-6 py-3 text-right">Actions</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {(sources || []).length > 0 ? sources.map((source) => (
                                    <tr key={source.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-slate-800">{source.name}</div>
                                            {source.description && (
                                                <div className="mt-0.5 text-xs text-slate-400">{source.description}</div>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <code className="rounded bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                                                {source.key || '—'}
                                            </code>
                                        </td>
                                        <td className="px-6 py-4 text-right font-bold text-slate-800">
                                            {source.percentage !== null ? `${source.percentage}%` : '—'}
                                        </td>
                                        <td className="px-6 py-4 text-right text-slate-600">
                                            {source.subsidy_count}
                                            <span className="ml-1 text-xs text-slate-400">subsidies</span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${source.is_active
                                                ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                                : 'border-slate-200 bg-slate-100 text-slate-500'}`}>
                                                <span className={`h-1.5 w-1.5 rounded-full ${source.is_active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                                                {source.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        {canManage && (
                                            <td className="whitespace-nowrap px-6 py-4 text-right">
                                                <button type="button" onClick={() => openEdit(source)} className="font-medium text-[var(--accent)] hover:opacity-80">
                                                    Edit
                                                </button>
                                                <button type="button" onClick={() => setConfirmDelete(source)} className="ml-4 font-medium text-rose-500 hover:text-rose-700">
                                                    Delete
                                                </button>
                                            </td>
                                        )}
                                    </tr>
                                )) : (
                                    <tr>
                                        <td colSpan={canManage ? 6 : 5} className="py-14 text-center">
                                            <p className="text-sm font-semibold text-slate-600">No funding sources yet.</p>
                                            <p className="mt-1 text-xs text-slate-400">Add the authorities or bodies that fund your meals.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Create / edit modal */}
            <Modal
                open={modalOpen}
                onClose={closeModal}
                title={editing ? `Edit ${editing.name}` : 'Add Funding Source'}
                description="Give the source a clear name and the share of the pool it should carry."
                footer={
                    <>
                        <button type="button" onClick={closeModal} className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" form="source-form" disabled={processing} className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50">
                            {processing && <Spinner className="h-4 w-4" />}
                            {processing ? 'Saving...' : editing ? 'Save Changes' : 'Add Source'}
                        </button>
                    </>
                }
            >
                <form id="source-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label="Source Name"
                        name="name"
                        required
                        value={data.name}
                        error={errors.name}
                        placeholder="e.g. University Authority"
                        onChange={(e) => setData('name', e.target.value)}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Key"
                            name="key"
                            value={data.key}
                            error={errors.key}
                            placeholder="Auto from name"
                            hint="Optional. Lowercase, underscores only."
                            onChange={(e) => setData('key', e.target.value)}
                        />
                        <Field
                            label="Default Share (%)"
                            name="percentage"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            value={data.percentage}
                            error={errors.percentage}
                            placeholder="e.g. 50"
                            hint="Share of the funding pool this source carries."
                            onChange={(e) => setData('percentage', e.target.value)}
                        />
                    </div>

                    <Field
                        label="Description"
                        name="description"
                        type="textarea"
                        value={data.description}
                        error={errors.description}
                        placeholder="Optional note about this funder..."
                        onChange={(e) => setData('description', e.target.value)}
                    />

                    {editing && (
                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                type="checkbox"
                                checked={Boolean(data.is_active)}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-[var(--accent)] focus:ring-[var(--accent-ring)]"
                            />
                            Active (selectable when recording a subsidy)
                        </label>
                    )}
                </form>
            </Modal>

            {/* Delete confirmation */}
            <Modal
                open={Boolean(confirmDelete)}
                onClose={() => setConfirmDelete(null)}
                title="Remove funding source"
                description={`Delete "${confirmDelete?.name}"? This cannot be undone.`}
                footer={
                    <>
                        <button type="button" onClick={() => setConfirmDelete(null)} className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="button" onClick={() => destroy(confirmDelete)} className="rounded-lg bg-rose-600 px-5 py-2 text-sm font-medium text-white hover:bg-rose-700">
                            Delete
                        </button>
                    </>
                }
            >
                <p className="text-sm text-slate-600">
                    A source already used by a recorded subsidy cannot be deleted - deactivate it
                    instead so it stops appearing in the subsidy form but keeps its history.
                </p>
            </Modal>
        </SettingsLayout>
    );
}
