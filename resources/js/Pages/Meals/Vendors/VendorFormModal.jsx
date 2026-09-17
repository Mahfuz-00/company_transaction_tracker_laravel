import React, { useEffect } from 'react';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import { useForm } from '@inertiajs/react';

/**
 * Vendor create / edit form, extracted from the (once 700-line) Vendors page.
 *
 * Self-contained: it owns its own Inertia form state and submits to the correct
 * endpoint based on `editing`. The parent only controls `open` and gets told
 * when to close via `onClose` (success) - so the page file stays a list view.
 */

const EMPTY_FORM = {
    name: '',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    category: '',
    recurrence: '',
    lead_time_days: '',
    recurring_amount: '',
    opening_balance: '',
    status: 'active',
    notes: '',
};

const categoryLabel = (value) =>
    value
        ? value.split('_').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join(' ')
        : '';

export default function VendorFormModal({ open, onClose, editing = null, categories = [], recurrences = [] }) {
    const isEditing = Boolean(editing);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...EMPTY_FORM });

    // Load the record being edited (or reset for create) whenever the modal
    // opens - keeps this component self-contained.
    useEffect(() => {
        if (!open) return;

        clearErrors();

        if (editing) {
            setData({
                name: editing.name || '',
                contact_person: editing.contact_person || '',
                phone: editing.phone || '',
                email: editing.email || '',
                address: editing.address || '',
                category: editing.category || '',
                recurrence: editing.recurrence || '',
                lead_time_days: editing.lead_time_days ?? '',
                recurring_amount: editing.recurring_amount ?? '',
                opening_balance: editing.opening_balance ?? '',
                status: editing.status || 'active',
                notes: editing.notes || '',
            });
        } else {
            reset();
            setData({ ...EMPTY_FORM });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id]);

    const categoryOptions = [
        { value: '', label: '— No category —' },
        ...categories.map((category) => ({ value: category, label: categoryLabel(category) })),
    ];

    const submit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (isEditing) {
            put(route('meals.vendors.update', editing.slug), options);
        } else {
            post(route('meals.vendors.store'), options);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={isEditing ? `Edit ${editing?.name}` : 'Add Vendor'}
            description={isEditing ? 'Update supplier details and status.' : 'Register a supplier the institution buys from.'}
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
                        form="vendor-form"
                        disabled={processing}
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                    >
                        {processing && (
                            <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                            </svg>
                        )}
                        {processing ? 'Saving...' : isEditing ? 'Save Changes' : 'Add Vendor'}
                    </button>
                </>
            }
        >
            <form id="vendor-form" onSubmit={submit} className="space-y-4">
                <Field
                    label="Vendor Name"
                    name="name"
                    required
                    value={data.name}
                    error={errors.name}
                    placeholder="e.g. Rahim General Store"
                    onChange={(event) => setData('name', event.target.value)}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Category"
                        name="category"
                        type="select"
                        value={data.category}
                        error={errors.category}
                        options={categoryOptions}
                        onChange={(event) => setData('category', event.target.value)}
                    />
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
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Contact Person"
                        name="contact_person"
                        value={data.contact_person}
                        error={errors.contact_person}
                        placeholder="e.g. Rahim Uddin"
                        onChange={(event) => setData('contact_person', event.target.value)}
                    />
                    <Field
                        label="Phone"
                        name="phone"
                        value={data.phone}
                        error={errors.phone}
                        placeholder="+8801XXXXXXXXX"
                        onChange={(event) => setData('phone', event.target.value)}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Email"
                        name="email"
                        type="email"
                        value={data.email}
                        error={errors.email}
                        placeholder="vendor@example.com"
                        onChange={(event) => setData('email', event.target.value)}
                    />
                    <Field
                        label="Opening Balance"
                        name="opening_balance"
                        type="number"
                        step="0.01"
                        min="0"
                        value={data.opening_balance}
                        error={errors.opening_balance}
                        placeholder="0.00"
                        hint="Amount already owed at setup."
                        onChange={(event) => setData('opening_balance', event.target.value)}
                    />
                </div>

                {/* Recurring purchase settings */}
                <div className="rounded-lg border-slate-200 bg-slate-50 p-4">
                    <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
                        Recurring Purchases
                    </p>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Buys From"
                            name="recurrence"
                            type="select"
                            value={data.recurrence}
                            error={errors.recurrence}
                            options={[{ value: '', label: '— One-off —' }, ...recurrences]}
                            onChange={(event) => setData('recurrence', event.target.value)}
                        />
                        <Field
                            label="Lead Time (days)"
                            name="lead_time_days"
                            type="number"
                            min="0"
                            value={data.lead_time_days}
                            error={errors.lead_time_days}
                            placeholder="e.g. 2"
                            onChange={(event) => setData('lead_time_days', event.target.value)}
                        />
                        <Field
                            label="Typical Order Value"
                            name="recurring_amount"
                            type="number"
                            step="0.01"
                            min="0"
                            value={data.recurring_amount}
                            error={errors.recurring_amount}
                            placeholder="e.g. 8000"
                            onChange={(event) => setData('recurring_amount', event.target.value)}
                        />
                    </div>
                </div>

                <Field
                    label="Address"
                    name="address"
                    value={data.address}
                    error={errors.address}
                    placeholder="Market, city"
                    onChange={(event) => setData('address', event.target.value)}
                />

                <Field
                    label="Notes"
                    name="notes"
                    type="textarea"
                    value={data.notes}
                    error={errors.notes}
                    placeholder="Payment terms, delivery days, etc."
                    onChange={(event) => setData('notes', event.target.value)}
                />
            </form>
        </Modal>
    );
}
