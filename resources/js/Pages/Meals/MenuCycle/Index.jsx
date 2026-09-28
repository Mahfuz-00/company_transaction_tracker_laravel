import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { Spinner } from '@/Components/UI/Loading';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * MENU CYCLE & PROCUREMENT FORECASTS.
 *
 * A repeating menu (e.g. a 7-day rotation) plus the SHOPPING LIST it implies. The
 * forecast is derived on every render from the current menu, roster and prices, so
 * it never goes stale - and the turnout slider lets a buyer model "what if only
 * 70% eat today?" without changing any stored data.
 */
export default function Index({ cycles = [], selected = null, forecast = null, turnout = 1, vendors = [] }) {
    const { flash } = usePage().props;

    const [createOpen, setCreateOpen] = useState(false);
    const [editingDay, setEditingDay] = useState(null);
    const [ingredientFor, setIngredientFor] = useState(null);

    const changeTurnout = (value) => {
        router.get(route('meals.menu-cycle.index'), {
            cycle: selected?.id,
            turnout: value,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-medium text-slate-500">
                            Plan the week's meals and see what to buy, and what it should cost.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setCreateOpen(true)}
                        data-testid="cycle-create-button"
                        className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        New menu cycle
                    </button>
                </div>
            }
        >
            <Head title="Menu Cycle & Procurement" />

            <div className="space-y-6">
                <PageHint title="From menu to shopping list">
                    Add the dishes for each day, then list the ingredients each dish needs per serving. The
                    forecast multiplies that by your expected eaters to give you quantities and a cost estimate.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="cycle-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {cycles.length === 0 ? (
                    <div className="rounded-2xl border border-slate-200 bg-white py-16 text-center shadow-sm">
                        <p className="text-sm font-semibold text-slate-600">No menu cycles yet.</p>
                        <p className="mt-1 text-xs text-slate-400">
                            Create one to start planning meals and forecasting what to buy.
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        {/* ---- Cycles list ---- */}
                        <div className="space-y-3">
                            <h3 className="text-sm font-bold text-slate-900">Your cycles</h3>
                            {cycles.map((cycle) => (
                                <button
                                    key={cycle.id}
                                    type="button"
                                    onClick={() => router.get(route('meals.menu-cycle.index'), { cycle: cycle.id, turnout }, { preserveScroll: true })}
                                    data-testid="cycle-card"
                                    className={`w-full rounded-xl border p-4 text-left transition-all ${selected?.id === cycle.id
                                        ? 'border-[var(--accent)] bg-[var(--accent-soft)] ring-1 ring-[var(--accent-ring)]'
                                        : 'border-slate-200 bg-white hover:border-slate-300'}`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-bold text-slate-800">{cycle.name}</span>
                                        {cycle.is_active && (
                                            <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                                                Active
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-[11px] text-slate-400">
                                        {cycle.cycle_length}-day rotation · {cycle.days_count} day(s) planned
                                    </p>
                                </button>
                            ))}
                        </div>

                        {/* ---- Forecast ---- */}
                        <div className="space-y-6 lg:col-span-2">
                            {forecast && (
                                <>
                                    {/* Turnout control */}
                                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h3 className="text-sm font-bold text-slate-900">Expected turnout</h3>
                                                <p className="mt-0.5 text-xs text-slate-500">
                                                    Not everyone eats every day. Adjust to model the forecast.
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <input
                                                    type="range"
                                                    min="0.3"
                                                    max="1"
                                                    step="0.05"
                                                    value={turnout}
                                                    data-testid="turnout-slider"
                                                    onChange={(e) => changeTurnout(e.target.value)}
                                                    className="w-40 accent-[var(--accent)]"
                                                />
                                                <span className="w-14 text-right text-sm font-bold text-slate-900">
                                                    {Math.round(turnout * 100)}%
                                                </span>
                                            </div>
                                        </div>

                                        <div className="mt-4 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4">
                                            <div>
                                                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                                    Expected eaters
                                                </p>
                                                <p data-testid="forecast-eaters" className="mt-0.5 text-xl font-bold text-slate-900">
                                                    {forecast.eaters}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                                    Cycle cost estimate
                                                </p>
                                                <p data-testid="forecast-total-cost" className="mt-0.5 text-xl font-bold text-rose-600">
                                                    {forecast.total_cost}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Warnings */}
                                    {forecast.warnings?.length > 0 && (
                                        <div data-testid="forecast-warnings" className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                            <p className="text-xs font-bold text-amber-800">Things to fix</p>
                                            <ul className="mt-1 list-disc space-y-1 pl-4 text-[11px] text-amber-700">
                                                {forecast.warnings.map((warning, index) => (
                                                    <li key={index}>{warning}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    {/* Consolidated shopping list */}
                                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                                            <h3 className="text-sm font-bold text-slate-900">Shopping list for the whole cycle</h3>
                                            <p className="mt-0.5 text-[11px] text-slate-500">
                                                Same ingredient across dishes, combined into one line.
                                            </p>
                                        </div>

                                        <div className="max-h-96 overflow-y-auto">
                                            <table className="w-full text-left text-sm">
                                                <thead className="sticky top-0 bg-white text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                                    <tr>
                                                        <th className="px-6 py-2">Ingredient</th>
                                                        <th className="px-6 py-2">Quantity</th>
                                                        <th className="px-6 py-2">Unit cost</th>
                                                        <th className="px-6 py-2 text-right">Cost</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {forecast.items?.length > 0 ? forecast.items.map((item, index) => (
                                                        <tr key={index} data-testid="shopping-row">
                                                            <td className="px-6 py-2.5 text-xs font-semibold text-slate-700">{item.name}</td>
                                                            <td className="px-6 py-2.5 text-xs text-slate-600">
                                                                {item.quantity} {item.unit}
                                                            </td>
                                                            <td className="px-6 py-2.5 text-xs text-slate-500">{item.unit_cost}</td>
                                                            <td className="px-6 py-2.5 text-right text-xs font-bold text-slate-900">{item.cost}</td>
                                                        </tr>
                                                    )) : (
                                                        <tr>
                                                            <td colSpan={4} className="px-6 py-10 text-center text-xs text-slate-400">
                                                                No ingredients defined yet. Add them to a dish to see the shopping list.
                                                            </td>
                                                        </tr>
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    {/* Day-by-day plan */}
                                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                                            <h3 className="text-sm font-bold text-slate-900">Day by day</h3>
                                        </div>

                                        <div className="divide-y divide-slate-100">
                                            {forecast.days?.map((day) => (
                                                <div key={day.day_number} data-testid="cycle-day-row" className="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                                                    <div className="min-w-0">
                                                        <p className="text-xs font-bold text-slate-800">
                                                            Day {day.day_number} — {day.label}
                                                        </p>
                                                        <p className="mt-0.5 text-[11px] text-slate-500">
                                                            {day.dishes?.length > 0
                                                                ? day.dishes.map((dish) => `${dish.meal}: ${dish.dish}`).join(' · ')
                                                                : 'No dishes planned'}
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-shrink-0 items-center gap-3">
                                                        <span className="text-xs font-semibold text-slate-700">{day.total_cost}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => setEditingDay(day)}
                                                            data-testid={`cycle-day-edit-${day.day_number}`}
                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                                        >
                                                            Edit dishes
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setIngredientFor({ dish: '', day: day.day_number })}
                                                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                                        >
                                                            + Ingredient
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                )}

                {/* ---- Ingredients ---- */}
                {selected?.ingredients?.length > 0 && (
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                            <h3 className="text-sm font-bold text-slate-900">Ingredient definitions</h3>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th className="px-6 py-2">Dish</th>
                                        <th className="px-6 py-2">Ingredient</th>
                                        <th className="px-6 py-2">Per serving</th>
                                        <th className="px-6 py-2">Unit cost</th>
                                        <th className="px-6 py-2">Vendor</th>
                                        <th className="px-6 py-2 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {selected.ingredients.map((ingredient) => (
                                        <tr key={ingredient.id} data-testid="ingredient-row">
                                            <td className="px-6 py-2.5 text-xs text-slate-600">{ingredient.dish || '—'}</td>
                                            <td className="px-6 py-2.5 text-xs font-semibold text-slate-800">{ingredient.name}</td>
                                            <td className="px-6 py-2.5 text-xs text-slate-600">
                                                {ingredient.qty_per_serving} {ingredient.unit}
                                            </td>
                                            <td className="px-6 py-2.5 text-xs text-slate-600">{ingredient.unit_cost}</td>
                                            <td className="px-6 py-2.5 text-[11px] text-slate-400">{ingredient.vendor || '—'}</td>
                                            <td className="px-6 py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => router.delete(route('meals.menu-cycle.ingredients.destroy', ingredient.id), { preserveScroll: true })}
                                                    className="text-[11px] font-semibold text-rose-500 hover:text-rose-700"
                                                >
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <InfoHint tone="indigo">
                    A <strong>Menu Cycle</strong> is a repeating plan. The forecast is recalculated every time you
                    open this page, so it always reflects your latest menu, roster and prices.
                </InfoHint>
            </div>

            <CreateCycleModal open={createOpen} onClose={() => setCreateOpen(false)} />
            {selected && editingDay && (
                <EditDayModal cycle={selected} day={editingDay} onClose={() => setEditingDay(null)} />
            )}
            {selected && ingredientFor && (
                <AddIngredientModal cycle={selected} preset={ingredientFor} vendors={vendors} onClose={() => setIngredientFor(null)} />
            )}
        </AuthenticatedLayout>
    );
}

/** Create a cycle. */
function CreateCycleModal({ open, onClose }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        description: '',
        cycle_length: 7,
        starts_on: new Date().toISOString().slice(0, 10),
        is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.menu-cycle.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); clearErrors(); onClose(); },
        });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="New menu cycle"
            description="A repeating plan of meals, e.g. a 7-day rotation."
            footer={
                <>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="cycle-create-form"
                        disabled={processing}
                        data-testid="cycle-submit"
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Creating...' : 'Create cycle'}
                    </button>
                </>
            }
        >
            <form id="cycle-create-form" onSubmit={submit} className="space-y-4">
                <Field label="Name" name="name" required value={data.name} error={errors.name} placeholder="e.g. Weekly rotation" onChange={(e) => setData('name', e.target.value)} />
                <Field label="Description" name="description" value={data.description} error={errors.description} onChange={(e) => setData('description', e.target.value)} />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Cycle length (days)" name="cycle_length" type="number" required min={1} max={31} value={data.cycle_length} error={errors.cycle_length} onChange={(e) => setData('cycle_length', e.target.value)} />
                    <Field label="Starts on" name="starts_on" type="date" value={data.starts_on} error={errors.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                </div>
                <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-[var(--accent)]" />
                    Active
                </label>
            </form>
        </Modal>
    );
}

/** Edit the dishes on one day of the cycle. */
function EditDayModal({ cycle, day, onClose }) {
    const [dishes, setDishes] = useState(day.dishes?.length > 0 ? day.dishes : [{ meal: 'lunch', dish: '', servings: 1 }]);

    const submit = (e) => {
        e.preventDefault();
        router.put(route('meals.menu-cycle.days.update', [cycle.id, day.day_number]), { dishes, label: day.label }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const setDish = (index, key, value) => {
        const next = [...dishes];
        next[index] = { ...next[index], [key]: value };
        setDishes(next);
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`Day ${day.day_number} — dishes`}
            description="List what is served on this day."
            maxWidth="max-w-xl"
            footer={
                <>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button type="submit" form="day-edit-form" data-testid="day-save" className="rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white hover:opacity-90">
                        Save day
                    </button>
                </>
            }
        >
            <form id="day-edit-form" onSubmit={submit} className="space-y-3">
                {dishes.map((dish, index) => (
                    <div key={index} className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3 sm:grid-cols-6">
                        <select
                            value={dish.meal}
                            onChange={(e) => setDish(index, 'meal', e.target.value)}
                            className="rounded-lg border-slate-300 text-xs sm:col-span-2"
                        >
                            <option value="breakfast">Breakfast</option>
                            <option value="lunch">Lunch</option>
                            <option value="dinner">Dinner</option>
                            <option value="snack">Snack</option>
                        </select>
                        <input
                            value={dish.dish}
                            onChange={(e) => setDish(index, 'dish', e.target.value)}
                            placeholder="Dish name"
                            data-testid={`day-dish-${index}`}
                            className="rounded-lg border-slate-300 text-xs sm:col-span-3"
                        />
                        <input
                            type="number"
                            step="0.1"
                            value={dish.servings}
                            onChange={(e) => setDish(index, 'servings', e.target.value)}
                            placeholder="Servings"
                            className="rounded-lg border-slate-300 text-xs"
                        />
                    </div>
                ))}

                <button
                    type="button"
                    onClick={() => setDishes([...dishes, { meal: 'dinner', dish: '', servings: 1 }])}
                    className="text-xs font-semibold text-[var(--accent)] hover:underline"
                >
                    + Add another dish
                </button>
            </form>
        </Modal>
    );
}

/** Add an ingredient line that drives the forecast. */
function AddIngredientModal({ cycle, preset, vendors = [], onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        dish: preset.dish || '',
        name: '',
        unit: 'kg',
        qty_per_serving: '',
        unit_cost: '',
        vendor_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.menu-cycle.ingredients.store', cycle.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title="Add an ingredient"
            description="What one serving of the dish requires, and its cost."
            footer={
                <>
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="ingredient-form"
                        disabled={processing}
                        data-testid="ingredient-submit"
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Saving...' : 'Add ingredient'}
                    </button>
                </>
            }
        >
            <form id="ingredient-form" onSubmit={submit} className="space-y-4">
                <Field
                    label="Dish"
                    name="dish"
                    required
                    value={data.dish}
                    error={errors.dish}
                    placeholder="e.g. Rice + Chicken"
                    hint="Must match the dish name used on the day's menu."
                    onChange={(e) => setData('dish', e.target.value)}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Ingredient" name="name" required value={data.name} error={errors.name} placeholder="e.g. Rice" onChange={(e) => setData('name', e.target.value)} />
                    <Field label="Unit" name="unit" required value={data.unit} error={errors.unit} placeholder="kg / litre / piece" onChange={(e) => setData('unit', e.target.value)} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Quantity per serving"
                        name="qty_per_serving"
                        type="number"
                        step="0.0001"
                        required
                        value={data.qty_per_serving}
                        error={errors.qty_per_serving}
                        placeholder="e.g. 0.15"
                        onChange={(e) => setData('qty_per_serving', e.target.value)}
                    />
                    <Field
                        label="Unit cost"
                        name="unit_cost"
                        type="number"
                        step="0.0001"
                        required
                        value={data.unit_cost}
                        error={errors.unit_cost}
                        placeholder="e.g. 75"
                        onChange={(e) => setData('unit_cost', e.target.value)}
                    />
                </div>
                <Field
                    label="Vendor"
                    name="vendor_id"
                    type="select"
                    value={data.vendor_id}
                    error={errors.vendor_id}
                    options={[{ value: '', label: 'No preferred vendor' }, ...vendors.map((v) => ({ value: v.id, label: v.name }))]}
                    onChange={(e) => setData('vendor_id', e.target.value)}
                />
            </form>
        </Modal>
    );
}