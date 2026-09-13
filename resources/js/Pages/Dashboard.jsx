import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Inertia } from '@inertiajs/inertia';
import { useState, useEffect, useRef } from 'react';
import StatCard from '@/Components/StatCard';

export default function Dashboard({ auth, transactions, currentBalance, monthIncome, monthExpense }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        item: '',
        type: 'in',
        amount: '',
        category: '',
    });

    const { data: filters, setData: setFilter, processing: filtering, reset: resetFilters } = useForm({
        date_from: '',
        date_to: '',
        type: 'all',
        amount_min: '',
        amount_max: '',
        sort_amount: '',
        per_page: '10',
    });

    const [filteringLoading, setFilteringLoading] = useState(false);
    const [sortingLoading, setSortingLoading] = useState(false);

    const [alert, setAlert] = useState(null);
    const [localTransactions, setLocalTransactions] = useState([]);
    const [visibleIds, setVisibleIds] = useState(new Set());
    const mountedRef = useRef(false);
    const prevIdsRef = useRef(new Set());

    // sync incoming prop updates into localTransactions and animate new rows
    useSyncTransactions(transactions, setLocalTransactions, computeRunningBalances, setVisibleIds, mountedRef, prevIdsRef);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('transactions.store'), {
            onSuccess: () => {
                // rely on server props to update the list, avoid optimistic temp rows
                reset();
                setAlert({ type: 'success', message: 'Transaction logged successfully.' });
                setTimeout(() => setAlert(null), 3000);
            },
            onError: () => {
                setAlert({ type: 'error', message: 'Failed to save transaction.' });
            }
        });
    };

    const handleFilterSubmit = (e) => {
        if (e) e.preventDefault();

        // Normalize date range: if only one provided, use it for both; ensure from <= to
        let from = filters.date_from || '';
        let to = filters.date_to || '';
        if (from && !to) to = from;
        if (to && !from) from = to;
        if (from && to && from > to) { const tmp = from; from = to; to = tmp; }

        // Normalize amount range: if only one provided, copy to the other
        let min = filters.amount_min || '';
        let max = filters.amount_max || '';
        if (min && !max) max = min;
        if (max && !min) min = max;

        const params = {
            date_from: from || undefined,
            date_to: to || undefined,
            type: filters.type && filters.type !== 'all' ? filters.type : undefined,
            amount_min: min || undefined,
            amount_max: max || undefined,
            sort_amount: filters.sort_amount || undefined,
            per_page: filters.per_page || undefined,
        };

        setFilteringLoading(true);
        Inertia.get(route('dashboard'), params, {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => setFilteringLoading(false),
            onCancel: () => setFilteringLoading(false),
        });
    };

    const handleClearFilters = () => {
        resetFilters();
        setFilteringLoading(true);
        Inertia.get(route('dashboard'), {}, { preserveState: false, preserveScroll: true, onFinish: () => setFilteringLoading(false) });
    };

    const getPaginationParams = () => ({
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        type: filters.type && filters.type !== 'all' ? filters.type : undefined,
        amount_min: filters.amount_min || undefined,
        amount_max: filters.amount_max || undefined,
        sort_amount: filters.sort_amount || undefined,
        per_page: filters.per_page || undefined,
    });

    // compute running balances for an oldest-first list
    function computeRunningBalances(list) {
        let balance = 0;
        return list.map((t) => {
            const effective = t.type === 'in' ? Number(t.amount) : -Number(t.amount);
            balance += effective;
            return { ...t, running_balance: balance };
        });
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Transaction Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-6 px-6 w-full space-y-6">
                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <StatCard title="Current Total Balance" value={`$${currentBalance.toFixed(2)}`} accent={currentBalance >= 0 ? 'green' : 'red'} />
                    <StatCard title="This Month Expense" value={`-$${Number(monthExpense || 0).toFixed(2)}`} accent="red" />
                    <StatCard title="This Month Revenue" value={`$${Number(monthIncome || 0).toFixed(2)}`} accent="green" />
                </div>

                {/* Add Transaction Form */}
                <div className="p-6 md:p-8 bg-white/6 backdrop-blur-md rounded-xl shadow-lg border border-white/10">
                    <h3 className="text-xl font-bold text-gray-900 mb-6">Add New Transaction</h3>
                    
                    <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        
                        {/* Item Description (Full Width) */}
                        <div className="md:col-span-2">
                            <label className="text-sm font-medium text-gray-700 mb-1.5 block">
                                Item description
                            </label>
                            <input
                                type="text"
                                placeholder="e.g., Office Supplies, Client Payment"
                                className="w-full border border-gray-300 rounded-lg shadow-sm px-4 h-11 text-gray-900 bg-white/90 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition disabled:opacity-50"
                                value={data.item}
                                onChange={(e) => setData('item', e.target.value)}
                                autoFocus
                                disabled={processing}
                            />
                            {errors.item && <span className="text-red-500 text-xs mt-1 block">{errors.item}</span>}
                        </div>

                        {/* Transaction Type (Left Column - No Border Box, Extra Gap) */}
                        <div>
                            <label className="text-sm font-medium text-gray-700 mb-2 block">
                                Transaction type
                            </label>
                            <div className="flex items-center gap-8 h-11">
                                <label className="inline-flex items-center gap-2.5 cursor-pointer text-sm font-medium text-gray-800">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="in"
                                        checked={data.type === 'in'}
                                        onChange={() => setData('type', 'in')}
                                        disabled={processing}
                                        className="w-4 h-4 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="text-green-700 font-semibold">Cash In (+)</span>
                                </label>

                                <label className="inline-flex items-center gap-2.5 cursor-pointer text-sm font-medium text-gray-800">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="out"
                                        checked={data.type === 'out'}
                                        onChange={() => setData('type', 'out')}
                                        disabled={processing}
                                        className="w-4 h-4 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="text-red-700 font-semibold">Cash Out (-)</span>
                                </label>
                            </div>
                        </div>

                        {/* Amount (Right Column) */}
                        <div>
                            <label className="text-sm font-medium text-gray-700 mb-1.5 block">
                                Amount
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                className="w-full border border-gray-300 rounded-lg shadow-sm px-4 h-11 text-gray-900 bg-white/90 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition disabled:opacity-50"
                                value={data.amount}
                                min="0.01"
                                inputMode="decimal"
                                onChange={(e) => {
                                    let v = e.target.value;
                                    if (typeof v === 'string' && v.startsWith('-')) v = v.slice(1);
                                    setData('amount', v);
                                }}
                                disabled={processing}
                            />
                            {errors.amount && <span className="text-red-500 text-xs mt-1 block">{errors.amount}</span>}
                        </div>

                        {/* Category (Left Column on Desktop) */}
                        <div>
                            <label className="text-sm font-medium text-gray-700 mb-1.5 block">
                                Category (optional)
                            </label>
                            <input
                                type="text"
                                placeholder="e.g., Utilities, Salary"
                                className="w-full border border-gray-300 rounded-lg shadow-sm px-4 h-11 text-gray-900 bg-white/90 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition disabled:opacity-50"
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                disabled={processing}
                            />
                            {errors.category && <span className="text-red-500 text-xs mt-1 block">{errors.category}</span>}
                        </div>

                        {/* Submit Button (Right Column, Aligned with Bottom) */}
                        <div className="flex items-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="bg-indigo-600 text-white font-semibold rounded-lg h-11 w-full hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200 transition disabled:opacity-50 flex items-center justify-center shadow-sm"
                            >
                                {processing && (
                                    <svg className="animate-spin h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                )}
                                {processing ? 'Saving...' : 'Save Transaction'}
                            </button>
                        </div>

                    </form>
                </div>

                {/* Alert */}
                {alert && (
                    <div className={`fixed top-6 right-6 px-4 py-2 rounded shadow ${alert.type === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'}`} role="status">
                        {alert.message}
                    </div>
                )}

                {/* Filters */}
                <div className="p-4 bg-white/6 backdrop-blur-md rounded-xl shadow-lg">
                    <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                        <div className="border-r border-gray-200 pr-3">
                            <label className="text-xs text-gray-600">From</label>
                            <input type="date" className="w-full border-gray-300 rounded-md shadow-sm px-2 py-1 text-black bg-white/90" value={filters.date_from} onChange={(e) => setFilter('date_from', e.target.value)} />
                        </div>
                        <div className="border-r border-gray-200 pr-3">
                            <label className="text-xs text-gray-600">To</label>
                            <input type="date" className="w-full border-gray-300 rounded-md shadow-sm px-2 py-1 text-black bg-white/90" value={filters.date_to} onChange={(e) => setFilter('date_to', e.target.value)} />
                        </div>
                        <div className="border-r border-gray-200 pr-3">
                            <label className="text-xs text-gray-600">Type</label>
                            <select className="w-full border-gray-300 rounded-md shadow-sm px-2 py-1 text-black bg-white/90" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                                <option value="all">All</option>
                                <option value="in">Cash In</option>
                                <option value="out">Cash Out</option>
                            </select>
                        </div>
                        <div className="border-r border-gray-200 pr-3">
                            <label className="text-xs text-gray-600">Amount Min</label>
                            <input type="number" step="0.01" className="w-full border-gray-300 rounded-md shadow-sm px-2 py-1 text-black bg-white/90" value={filters.amount_min} onChange={(e) => setFilter('amount_min', e.target.value)} />
                        </div>
                        <div className="border-r border-gray-200 pr-3">
                            <label className="text-xs text-gray-600">Amount Max</label>
                            <input type="number" step="0.01" className="w-full border-gray-300 rounded-md shadow-sm px-2 py-1 text-black bg-white/90" value={filters.amount_max} onChange={(e) => setFilter('amount_max', e.target.value)} />
                        </div>
                        <div className="md:col-span-1 flex flex-col items-end gap-2">
                            <select
                                className="w-full md:w-40 border-gray-300 rounded-md px-3 py-2 pr-10 text-sm bg-white/90 text-black"
                                value={filters.per_page}
                                onChange={(e) => {
                                    const newPer = e.target.value;
                                    setFilter('per_page', newPer);
                                    setFilteringLoading(true);
                                    const params = {
                                        ...getPaginationParams(),
                                        per_page: newPer,
                                    };
                                    Inertia.get(route('dashboard'), { ...params, page: 1 }, {
                                        preserveState: false,
                                        preserveScroll: true,
                                        onFinish: () => setFilteringLoading(false),
                                        onCancel: () => setFilteringLoading(false),
                                    });
                                }}
                            >
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="50">50</option>
                            </select>
                            <div className="flex w-full md:w-auto gap-2">
                                <button type="submit" className="w-full md:w-auto bg-indigo-600 text-white py-1 px-3 rounded flex items-center justify-center" disabled={filteringLoading}>
                                    {filteringLoading ? (
                                        <svg className="animate-spin h-4 w-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                    ) : null}
                                    {filteringLoading ? 'Filtering...' : 'Apply'}
                                </button>
                                <button type="button" className="w-full md:w-auto bg-gray-200 text-gray-800 py-1 px-3 rounded" onClick={handleClearFilters}>Clear</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div className="bg-white rounded-xl shadow-lg overflow-hidden">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-white/6 text-gray-700 text-sm uppercase">
                                <th className="p-4">Date</th>
                                <th className="p-4">Item Description</th>
                                <th className="p-4">Category</th>
                                <th className="p-4 cursor-pointer" onClick={() => {
                                    const next = filters.sort_amount === 'asc' ? 'desc' : (filters.sort_amount === 'desc' ? '' : 'asc');
                                    setFilter('sort_amount', next);
                                    const params = {
                                        date_from: filters.date_from || undefined,
                                        date_to: filters.date_to || undefined,
                                        type: filters.type && filters.type !== 'all' ? filters.type : undefined,
                                        amount_min: filters.amount_min || undefined,
                                        amount_max: filters.amount_max || undefined,
                                        sort_amount: next || undefined,
                                    };

                                    setSortingLoading(true);
                                    Inertia.get(route('dashboard'), params, {
                                        preserveState: false,
                                        preserveScroll: true,
                                        onFinish: () => setSortingLoading(false),
                                        onCancel: () => setSortingLoading(false),
                                    });
                                }}>
                                    Amount
                                    <span className="ml-2 text-xs text-gray-500">{filters.sort_amount === 'asc' ? '↑' : (filters.sort_amount === 'desc' ? '↓' : '')}</span>
                                    {sortingLoading && (
                                        <svg className="animate-spin inline-block h-4 w-4 ml-2 text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                    )}
                                </th>
                                <th className="p-4">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                            {localTransactions.length > 0 ? (
                                localTransactions.map((t) => (
                                    <tr
                                        key={t.id}
                                        className={`${t.type === 'in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'} hover:bg-gray-50`}
                                        style={{
                                            transformOrigin: 'top',
                                            transform: visibleIds.has(t.id) ? 'scaleY(1)' : 'scaleY(0)',
                                            transition: 'transform 320ms cubic-bezier(0.2,0.8,0.2,1), opacity 200ms',
                                            opacity: visibleIds.has(t.id) ? 1 : 0,
                                        }}
                                    >
                                        <td className="p-4 text-sm text-gray-700">{t.created_at}</td>
                                        <td className="p-4 font-medium text-gray-900">{t.item}</td>
                                        <td className="p-4 text-sm text-gray-600">{t.category || '-'}</td>
                                        <td className="p-4 font-bold text-gray-900">
                                            {t.type === 'in' ? `+$${Number(t.amount).toFixed(2)}` : `-$${Math.abs(Number(t.amount)).toFixed(2)}`}
                                        </td>
                                        <td className="p-4 font-semibold text-gray-900">${Number(t.running_balance || 0).toFixed(2)}</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="5" className="p-4 text-center text-gray-500">
                                        No transactions recorded yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                </div>
                {/* Pagination Controls */}
                {transactions && transactions.meta && (
                    <div className="flex items-center justify-between mt-4">
                        <div className="text-sm text-gray-600">
                            Showing {((transactions.meta.current_page - 1) * transactions.meta.per_page) + 1}-{Math.min(transactions.meta.total, transactions.meta.current_page * transactions.meta.per_page)} of {transactions.meta.total} transactions
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                className="px-2 py-1 border rounded"
                                disabled={transactions.meta.current_page <= 1}
                                onClick={() => Inertia.get(route('dashboard'), { ...getPaginationParams(), page: transactions.meta.current_page - 1 }, { preserveState: false })}
                            >Prev</button>
                            {/* simple page numbers */}
                            {Array.from({ length: transactions.meta.last_page }).map((_, i) => (
                                <button key={i} className={`px-2 py-1 border rounded ${transactions.meta.current_page === i+1 ? 'bg-gray-200' : ''}`} onClick={() => Inertia.get(route('dashboard'), { ...getPaginationParams(), page: i+1 }, { preserveState: false })}>{i+1}</button>
                            ))}
                            <button
                                className="px-2 py-1 border rounded"
                                disabled={transactions.meta.current_page >= transactions.meta.last_page}
                                onClick={() => Inertia.get(route('dashboard'), { ...getPaginationParams(), page: transactions.meta.current_page + 1 }, { preserveState: false })}
                            >Next</button>
                        </div>
                    </div>
                )}
        </AuthenticatedLayout>
    );
}

// helper to collect current filter params for pagination/sort navigation
// (pagination params helper inserted inside component scope)

// Sync props -> localTransactions on mount and when `transactions` prop changes.
function useSyncTransactions(transactions, setLocalTransactions, computeRunningBalances, setVisibleIds, mountedRef, prevIdsRef) {
    useEffect(() => {
        // transactions may be an array or a paginator with .data; extract list (newest-first)
        const rawList = Array.isArray(transactions) ? transactions : (transactions && transactions.data) ? transactions.data : [];
        // ensure we work on a shallow copy
        const list = [...rawList].slice().reverse(); // oldest-first for running balance
        const withBalance = computeRunningBalances(list);

        // detect newly added ids compared to previous
        const newIds = [];
        const incomingIds = new Set(withBalance.map((t) => t.id));
        const prevIds = prevIdsRef.current || new Set();

        withBalance.forEach((t) => {
            if (!prevIds.has(t.id)) newIds.push(t.id);
        });

        // update the local list to server-provided list (prevents duplicates)
        setLocalTransactions(withBalance);

        // For initial load, mark all visible. For subsequent updates, animate only new items.
        if (!mountedRef.current) {
            setVisibleIds(incomingIds);
            mountedRef.current = true;
        } else if (newIds.length > 0) {
            // ensure existing items remain visible
            setVisibleIds((prev) => new Set(prev));

            // keep new items hidden briefly, then reveal to trigger animation
            setTimeout(() => {
                setVisibleIds((prev) => {
                    const next = new Set(prev);
                    newIds.forEach((id) => next.add(id));
                    return next;
                });
            }, 40);
        }

        // store current ids for next diff
        prevIdsRef.current = incomingIds;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [transactions]);
}