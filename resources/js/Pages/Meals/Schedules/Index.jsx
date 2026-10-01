import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { HelpBadge, PageHint } from '@/Components/Help/HelpHint';
import { Head, useForm } from '@inertiajs/react';

export default function Index({ schedules = { data: [] }, student }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        status: 'off',
        recurrence: 'one_time',
        starts_on: '',
        ends_on: '',
        interval_days: '',
        weekdays: '1,2,3,4,5',
        breakfast: true,
        lunch: true,
        dinner: true,
        reason: '',
    });

    const [formOpen, setFormOpen] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.schedules.store'), {
            onSuccess: () => {
                setFormOpen(false);
                reset();
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-bold text-slate-900">Meal Scheduling &amp; Notices</h2>
                        <p className="text-xs text-slate-500">Notify the meal manager whether you will take meals on dates or date ranges.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setFormOpen(!formOpen)}
                        data-testid="create-schedule-btn"
                        className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700"
                    >
                        {formOpen ? 'Cancel' : '+ New Meal Schedule Notice'}
                    </button>
                </div>
            }
        >
            <Head title="Meal Scheduling" />

            <div className="space-y-6">
                <PageHint title="Declare Meal Participation in Advance">
                    Let your meal manager know when you will be absent (off) or eating (on) regularly, at custom intervals, or for one-time trips.
                </PageHint>

                {formOpen && (
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-base font-bold text-slate-900">New Schedule Notice</h3>
                            <HelpBadge title="Meal Scheduling Options">
                                Set whether you will take meals or not. Choose between one-time date ranges, daily repetition, weekly patterns, or custom intervals.
                            </HelpBadge>
                        </div>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700">Participation Status</label>
                                    <select
                                        value={data.status}
                                        onChange={(e) => setData('status', e.target.value)}
                                        data-testid="schedule-status-select"
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-xs font-semibold"
                                    >
                                        <option value="off">Off (Will NOT take meals)</option>
                                        <option value="on">On (Will take meals)</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700">Recurrence</label>
                                    <select
                                        value={data.recurrence}
                                        onChange={(e) => setData('recurrence', e.target.value)}
                                        data-testid="schedule-recurrence-select"
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-xs font-semibold"
                                    >
                                        <option value="one_time">One-time / Specific Dates</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly (Selected Weekdays)</option>
                                        <option value="custom">Custom Interval (Every N days)</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700">Starts On</label>
                                    <input
                                        type="date"
                                        value={data.starts_on}
                                        onChange={(e) => setData('starts_on', e.target.value)}
                                        data-testid="schedule-starts-on"
                                        required
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-xs"
                                    />
                                    {errors.starts_on && <p className="text-xs text-rose-500 mt-1">{errors.starts_on}</p>}
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700">Ends On (Inclusive)</label>
                                    <input
                                        type="date"
                                        value={data.ends_on}
                                        onChange={(e) => setData('ends_on', e.target.value)}
                                        data-testid="schedule-ends-on"
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-xs"
                                    />
                                </div>
                            </div>

                            {data.recurrence === 'custom' && (
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700">Interval (Days)</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="90"
                                        placeholder="e.g. 2 for every other day"
                                        value={data.interval_days}
                                        onChange={(e) => setData('interval_days', e.target.value)}
                                        data-testid="schedule-interval-days"
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-xs"
                                    />
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-slate-700">Reason / Notes</label>
                                <input
                                    type="text"
                                    placeholder="e.g. Vacation, attending seminar, fasting"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    data-testid="schedule-reason"
                                    className="mt-1 block w-full rounded-xl border-slate-300 text-xs"
                                />
                            </div>

                            <div className="flex items-center gap-4 pt-2">
                                <label className="flex items-center gap-1.5 text-xs text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={data.breakfast}
                                        onChange={(e) => setData('breakfast', e.target.checked)}
                                        className="rounded border-slate-300 text-indigo-600"
                                    />
                                    Breakfast
                                </label>
                                <label className="flex items-center gap-1.5 text-xs text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={data.lunch}
                                        onChange={(e) => setData('lunch', e.target.checked)}
                                        className="rounded border-slate-300 text-indigo-600"
                                    />
                                    Lunch
                                </label>
                                <label className="flex items-center gap-1.5 text-xs text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={data.dinner}
                                        onChange={(e) => setData('dinner', e.target.checked)}
                                        className="rounded border-slate-300 text-indigo-600"
                                    />
                                    Dinner
                                </label>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                data-testid="submit-schedule-btn"
                                className="rounded-xl bg-indigo-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Submit Notice
                            </button>
                        </form>
                    </div>
                )}

                <div className="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
                    <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-900">Your Submitted Notices</h3>
                        <HelpBadge title="Manager Status">
                            Pending notices are awaiting acknowledgement from your meal manager.
                        </HelpBadge>
                    </div>
                    {schedules.data.length === 0 ? (
                        <div className="p-8 text-center text-xs text-slate-500">
                            You have not submitted any meal schedule notices yet.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs text-slate-600">
                                <thead className="bg-slate-50 border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th className="px-6 py-3">Dates</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3">Recurrence</th>
                                        <th className="px-4 py-3">Reason</th>
                                        <th className="px-4 py-3">Manager Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {schedules.data.map((row) => (
                                        <tr key={row.id} className="border-b border-slate-100" data-testid={`schedule-row-${row.id}`}>
                                            <td className="px-6 py-3 font-semibold text-slate-800">
                                                {row.starts_on} {row.ends_on && row.ends_on !== row.starts_on ? `→ ${row.ends_on}` : ''}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${row.status === 'off' ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                    }`}>
                                                    {row.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 capitalize">{row.recurrence}</td>
                                            <td className="px-4 py-3">{row.reason || '—'}</td>
                                            <td className="px-4 py-3">
                                                <span className="capitalize font-medium text-slate-700">{row.manager_status}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
