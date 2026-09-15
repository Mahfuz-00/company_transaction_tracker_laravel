import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { Inertia } from '@inertiajs/inertia';
import { useState, useEffect, useRef } from 'react';
import { useCurrencySettings, formatCurrencyValue } from '@/Utils/useCurrency';
import Button from '@/Components/UI/Button';
import StatCard from '@/Components/StatCard';

export default function Dashboard({ auth, transactions, currentBalance, monthIncome, monthExpense }) {
    // Standardize pagination metadata detection across API resources and direct LengthAwarePaginator
    const paginationMeta = {
        current_page: Number(transactions?.meta?.current_page ?? transactions?.current_page ?? 1),
        last_page: Number(transactions?.meta?.last_page ?? transactions?.last_page ?? 1),
        per_page: Number(transactions?.meta?.per_page ?? transactions?.per_page ?? 10),
        total: Number(transactions?.meta?.total ?? transactions?.total ?? 0),
    };

    // Initialize per_page: prefer URL query param, fallback to localStorage, default to 10
    let initialPerPage = '10';
    try {
        const url = typeof window !== 'undefined' ? new URL(window.location.href) : null;
        const qp = url ? (url.searchParams.get('per_page') || url.searchParams.get('limit')) : null;
        if (qp) {
            initialPerPage = qp;
        } else {
            const stored = localStorage.getItem('per_page') || localStorage.getItem('transactions_per_page');
            if (stored) initialPerPage = stored;
        }
    } catch (e) { /* ignore */ }

    const { data: filters, setData: setFilter, processing: filtering, reset: resetFilters } = useForm({
        date_from: '',
        date_to: '',
        type: 'all',
        search: '',
        amount_min: '',
        amount_max: '',
        sort_amount: '',
        per_page: initialPerPage,
    });

    const [filteringLoading, setFilteringLoading] = useState(false);
    const [sortingLoading, setSortingLoading] = useState(false);

    const [alert, setAlert] = useState(null);
    const [localTransactions, setLocalTransactions] = useState([]);
    const [visibleIds, setVisibleIds] = useState(new Set());
    const [currency] = useCurrencySettings();
    const [blurAmounts, setBlurAmounts] = useState(() => {
        try { return JSON.parse(localStorage.getItem('blurAmounts')) !== false; } catch (e) { return true; }
    });
    const [revealedAmountId, setRevealedAmountId] = useState(null);
    const [revealedBalanceId, setRevealedBalanceId] = useState(null);
    const mountedRef = useRef(false);
    const prevIdsRef = useRef(new Set());

    // Sync incoming prop updates into localTransactions and animate new rows
    useSyncTransactions(transactions, setLocalTransactions, computeRunningBalances, setVisibleIds, mountedRef, prevIdsRef);

    const handleFilterSubmit = (e) => {
        if (e) e.preventDefault();

        let from = filters.date_from || '';
        let to = filters.date_to || '';
        if (from && !to) to = from;
        if (to && !from) from = to;
        if (from && to && from > to) { const tmp = from; from = to; to = tmp; }

        const min = filters.amount_min !== '' ? filters.amount_min : undefined;
        const max = filters.amount_max !== '' ? filters.amount_max : undefined;

        const params = {
            search: filters.search || undefined,
            date_from: from || undefined,
            date_to: to || undefined,
            type: filters.type && filters.type !== 'all' ? filters.type : undefined,
            amount_min: min,
            amount_max: max,
            sort_amount: filters.sort_amount || undefined,
            per_page: filters.per_page || undefined,
            page: 1,
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
        try {
            const stored = localStorage.getItem('per_page') || localStorage.getItem('transactions_per_page');
            if (stored) setFilter('per_page', stored);
        } catch (e) {}
        setFilteringLoading(true);
        Inertia.get(route('dashboard'), { per_page: filters.per_page, page: 1 }, { preserveState: false, preserveScroll: true, onFinish: () => setFilteringLoading(false) });
    };

    const getPaginationParams = () => ({
        search: filters.search || undefined,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        type: filters.type && filters.type !== 'all' ? filters.type : undefined,
        amount_min: filters.amount_min || undefined,
        amount_max: filters.amount_max || undefined,
        sort_amount: filters.sort_amount || undefined,
        per_page: filters.per_page || undefined,
    });

    const toggleBlurAmounts = () => {
        const next = !blurAmounts;
        setBlurAmounts(next);
        try { localStorage.setItem('blurAmounts', JSON.stringify(next)); } catch (e) {}
    };

    function computeRunningBalances(list) {
        let balance = 0;
        return list.map((t) => {
            const effective = t.type === 'in' ? Number(t.amount) : -Number(t.amount);
            balance += effective;
            return { ...t, running_balance: balance };
        });
    }

    if (!currency || typeof currency !== 'object') {
        return (
            <AuthenticatedLayout user={auth.user} header={<h2 className="font-bold text-2xl text-slate-900 tracking-tight">Financial Overview</h2>}>
                <div className="flex items-center justify-center min-h-[450px]">
                    <div className="flex flex-col items-center gap-3 p-8 rounded-2xl bg-white/60 backdrop-blur-md border border-slate-100 shadow-xl shadow-slate-200/50">
                        <svg className="animate-spin h-9 w-9 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                            <path className="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <p className="text-sm font-semibold text-slate-600">Preparing workspace dashboard...</p>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                    <div>
                        <h2 className="font-bold text-2xl text-slate-900 tracking-tight">Dashboard Overview</h2>
                        <p className="text-xs text-slate-500 font-medium">Track your income, expenses, and running account balances</p>
                    </div>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-8">
                {/* Summary Metric Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <StatCard 
                        title="Current Total Balance" 
                        value={formatCurrencyValue(currentBalance, currency || { symbol: '$' })} 
                        accent={currentBalance >= 0 ? 'green' : 'red'} 
                    />
                    <StatCard 
                        title="This Month Expense" 
                        value={`-${formatCurrencyValue(Number(monthExpense || 0), currency || { symbol: '$' })}`} 
                        accent="red" 
                    />
                    <StatCard 
                        title="This Month Revenue" 
                        value={formatCurrencyValue(Number(monthIncome || 0), currency || { symbol: '$' })} 
                        accent="green" 
                    />
                </div>

                {/* Toast Notification Alert */}
                {alert && (
                    <div className={`fixed top-6 right-6 z-50 px-5 py-3 rounded-xl shadow-2xl backdrop-blur-md flex items-center gap-3 transition-all duration-300 ${alert.type === 'success' ? 'bg-emerald-600/90 text-white shadow-emerald-500/20' : 'bg-rose-600/90 text-white shadow-rose-500/20'}`} role="status">
                        <span className="text-sm font-semibold">{alert.message}</span>
                    </div>
                )}

                {/* Filter and Query Section */}
                <div className="p-6 bg-white border border-slate-200/80 rounded-2xl shadow-sm transition-all duration-200 hover:shadow-md">
                    <form onSubmit={handleFilterSubmit} className="space-y-5">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
                            {/* Search Field */}
                            <div className="lg:col-span-2 space-y-1.5">
                                <label className="text-xs font-bold uppercase tracking-wider text-slate-500">Search Keywords</label>
                                <div className="relative">
                                    <input 
                                        type="text" 
                                        placeholder="Search item, category..." 
                                        className="w-full pl-10 pr-4 py-2 text-sm border-slate-200 rounded-xl bg-slate-50/50 shadow-inner focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 text-slate-800 placeholder-slate-400 transition-all duration-200" 
                                        value={filters.search} 
                                        onChange={(e) => setFilter('search', e.target.value)} 
                                    />
                                    <svg className="w-4 h-4 text-slate-400 absolute left-3.5 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>

                            {/* Date Range Inputs */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold uppercase tracking-wider text-slate-500">From Date</label>
                                <input type="date" className="w-full border-slate-200 rounded-xl bg-slate-50/50 px-3 py-2 text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200" value={filters.date_from} onChange={(e) => setFilter('date_from', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold uppercase tracking-wider text-slate-500">To Date</label>
                                <input type="date" className="w-full border-slate-200 rounded-xl bg-slate-50/50 px-3 py-2 text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200" value={filters.date_to} onChange={(e) => setFilter('date_to', e.target.value)} />
                            </div>

                            {/* Type Selector */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold uppercase tracking-wider text-slate-500">Transaction Type</label>
                                <select className="w-full border-slate-200 rounded-xl bg-slate-50/50 px-3 py-2 text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                                    <option value="all">All Types</option>
                                    <option value="in">Cash In (+)</option>
                                    <option value="out">Cash Out (-)</option>
                                </select>
                            </div>

                            {/* Per Page Selector */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold uppercase tracking-wider text-slate-500">Rows Displayed</label>
                                <select
                                    className="w-full border-slate-200 rounded-xl bg-slate-50/50 px-3 py-2 text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200"
                                    value={filters.per_page}
                                    onChange={(e) => {
                                        const newPer = e.target.value;
                                        setFilter('per_page', newPer);
                                        try { localStorage.setItem('per_page', String(newPer)); } catch (e) {}
                                        setFilteringLoading(true);
                                        Inertia.get(route('dashboard'), { ...getPaginationParams(), per_page: newPer, page: 1 }, {
                                            preserveState: false,
                                            preserveScroll: true,
                                            onFinish: () => setFilteringLoading(false),
                                            onCancel: () => setFilteringLoading(false),
                                        });
                                    }}
                                >
                                    <option value="5">5 Per Page</option>
                                    <option value="10">10 Per Page</option>
                                    <option value="50">50 Per Page</option>
                                </select>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center justify-between pt-4 border-t border-slate-100 gap-4">
                            {/* Amount Range Filter */}
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Amount Filter:</span>
                                <input type="number" placeholder="Min" step="0.01" className="w-24 border-slate-200 rounded-lg bg-slate-50/50 px-3 py-1.5 text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" value={filters.amount_min} onChange={(e) => setFilter('amount_min', e.target.value)} />
                                <span className="text-slate-300 font-bold">—</span>
                                <input type="number" placeholder="Max" step="0.01" className="w-24 border-slate-200 rounded-lg bg-slate-50/50 px-3 py-1.5 text-sm text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" value={filters.amount_max} onChange={(e) => setFilter('amount_max', e.target.value)} />
                            </div>

                            {/* Toolbar Buttons */}
                            <div className="flex items-center gap-2.5">
                                <button
                                    type="button"
                                    onClick={toggleBlurAmounts}
                                    className={`p-2 rounded-xl border transition-all duration-200 ${blurAmounts ? 'bg-indigo-50/80 border-indigo-200 text-indigo-600 shadow-sm' : 'border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-700'}`}
                                    title={blurAmounts ? "Privacy Mode: Active (Click to Reveal)" : "Privacy Mode: Inactive"}
                                >
                                    {blurAmounts ? (
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858-5.908a8.962 8.962 0 013.682-.763c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m-4.092-4.092a3 3 0 11-4.243-4.243m4.242 4.242L3 3l18 18" />
                                        </svg>
                                    ) : (
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    )}
                                </button>
                                <Button type="button" variant="secondary" className="h-9 px-4 text-xs font-semibold rounded-xl" onClick={handleClearFilters}>Reset</Button>
                                <Button type="submit" className="h-9 px-5 text-xs font-semibold rounded-xl bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white shadow-md shadow-indigo-500/20 transition-all flex items-center gap-2" disabled={filteringLoading}>
                                    {filteringLoading && (
                                        <svg className="animate-spin h-3.5 w-3.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                    )}
                                    {filteringLoading ? 'Filtering...' : 'Apply Filters'}
                                </Button>
                            </div>
                        </div>
                    </form>
                </div>

                {/* Table Section */}
                <div className="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse">
                            <thead>
                                <tr className="bg-slate-50/80 border-b border-slate-200/80 text-slate-500 text-[11px] font-bold uppercase tracking-wider">
                                    <th className="py-3.5 px-5">Date</th>
                                    <th className="py-3.5 px-5">Description</th>
                                    <th className="py-3.5 px-5">Category</th>
                                    <th className="py-3.5 px-5">Method</th>
                                    <th className="py-3.5 px-5">Payer / Beneficiary</th>
                                    <th 
                                        className="py-3.5 px-5 cursor-pointer select-none hover:text-slate-900 transition-colors" 
                                        onClick={() => {
                                            const next = filters.sort_amount === 'asc' ? 'desc' : (filters.sort_amount === 'desc' ? '' : 'asc');
                                            setFilter('sort_amount', next);
                                            const params = { ...getPaginationParams(), sort_amount: next || undefined };
                                            setSortingLoading(true);
                                            Inertia.get(route('dashboard'), params, {
                                                preserveState: false,
                                                preserveScroll: true,
                                                onFinish: () => setSortingLoading(false),
                                                onCancel: () => setSortingLoading(false),
                                            });
                                        }}
                                    >
                                        <div className="flex items-center gap-1.5">
                                            <span>Amount</span>
                                            <span className="text-indigo-600 font-bold">{filters.sort_amount === 'asc' ? '↑' : (filters.sort_amount === 'desc' ? '↓' : '↕')}</span>
                                            {sortingLoading && (
                                                <svg className="animate-spin h-3 w-3 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                </svg>
                                            )}
                                        </div>
                                    </th>
                                    <th className="py-3.5 px-5">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {localTransactions.length > 0 ? (
                                    localTransactions.map((t) => (
                                        <tr
                                            key={t.id}
                                            className="hover:bg-slate-50/80 transition-colors duration-150"
                                            style={{
                                                transformOrigin: 'top',
                                                transform: visibleIds.has(t.id) ? 'scaleY(1)' : 'scaleY(0)',
                                                transition: 'transform 280ms cubic-bezier(0.16, 1, 0.3, 1), opacity 200ms',
                                                opacity: visibleIds.has(t.id) ? 1 : 0,
                                            }}
                                        >
                                            <td className="py-4 px-5 text-xs font-medium text-slate-500 whitespace-nowrap">{t.created_at}</td>
                                            <td className="py-4 px-5 text-sm font-semibold text-slate-800">{t.item}</td>
                                            <td className="py-4 px-5 text-xs text-slate-500">
                                                {t.category ? (
                                                    <span className="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 font-medium">{t.category}</span>
                                                ) : (
                                                    <span className="text-slate-300">—</span>
                                                )}
                                            </td>
                                            <td className="py-4 px-5 text-xs text-slate-500">{t.payment_method || '—'}</td>
                                            <td className="py-4 px-5 text-xs text-slate-500">{t.by_whom || '—'}</td>
                                            
                                            {/* Amount Column */}
                                            <td className="py-4 px-5 whitespace-nowrap">
                                                <button
                                                    type="button"
                                                    className={`font-bold text-sm focus:outline-none rounded-lg px-2 py-0.5 transition-all ${t.type === 'in' ? 'text-emerald-600 hover:bg-emerald-50' : 'text-rose-600 hover:bg-rose-50'}`}
                                                    aria-pressed={revealedAmountId === t.id}
                                                    aria-label={revealedAmountId === t.id ? 'Hide amount' : 'Reveal amount'}
                                                    onClick={() => setRevealedAmountId(revealedAmountId === t.id ? null : t.id)}
                                                >
                                                    <span style={{ filter: (blurAmounts && revealedAmountId !== t.id) ? 'blur(5px)' : 'none', transition: 'filter 150ms' }}>
                                                        {t.type === 'in' ? `+${formatCurrencyValue(Math.abs(Number(t.amount)), currency)}` : `-${formatCurrencyValue(Math.abs(Number(t.amount)), currency)}`}
                                                    </span>
                                                </button>
                                            </td>

                                            {/* Running Balance Column */}
                                            <td className="py-4 px-5 whitespace-nowrap">
                                                <button
                                                    type="button"
                                                    className="font-semibold text-sm text-slate-800 hover:bg-slate-100 focus:outline-none rounded-lg px-2 py-0.5 transition-all"
                                                    aria-pressed={revealedBalanceId === t.id}
                                                    aria-label={revealedBalanceId === t.id ? 'Hide balance' : 'Reveal balance'}
                                                    onClick={() => setRevealedBalanceId(revealedBalanceId === t.id ? null : t.id)}
                                                >
                                                    <span style={{ filter: (blurAmounts && revealedBalanceId !== t.id) ? 'blur(5px)' : 'none', transition: 'filter 150ms' }}>
                                                        {formatCurrencyValue(Number(t.running_balance || 0), currency)}
                                                    </span>
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="7" className="py-12 text-center">
                                            <div className="flex flex-col items-center justify-center gap-2">
                                                <svg className="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                                </svg>
                                                <p className="text-sm font-semibold text-slate-500">No transactions record found</p>
                                                <p className="text-xs text-slate-400">Try adjusting your filters or date range</p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Pagination Controls */}
                {/* Truncated Pagination Controls */}
{paginationMeta.total > 0 && (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2 text-sm">
        <div className="text-xs font-semibold text-slate-500">
            Showing <span className="text-slate-800">{((paginationMeta.current_page - 1) * paginationMeta.per_page) + 1}</span> to <span className="text-slate-800">{Math.min(paginationMeta.total, paginationMeta.current_page * paginationMeta.per_page)}</span> of <span className="text-slate-800">{paginationMeta.total}</span> entries
        </div>

        <div className="flex items-center gap-1.5">
            {/* Previous Button */}
            <button
                className="px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-xl bg-white shadow-sm disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50 text-slate-700 transition-all"
                disabled={paginationMeta.current_page <= 1}
                onClick={() => Inertia.get(route('dashboard'), { ...getPaginationParams(), page: paginationMeta.current_page - 1 }, { preserveState: false })}
            >
                Previous
            </button>

            {/* Truncated Page Numbers */}
            {(() => {
                const current = paginationMeta.current_page;
                const last = paginationMeta.last_page;
                const delta = 1;
                const range = [];

                for (let i = Math.max(2, current - delta); i <= Math.min(last - 1, current + delta); i++) {
                    range.push(i);
                }

                if (current - delta > 2) range.unshift('...');
                if (current + delta < last - 1) range.push('...');

                range.unshift(1);
                if (last > 1) range.push(last);

                return range.map((page, idx) => {
                    if (page === '...') {
                        return (
                            <span key={`ellipsis-${idx}`} className="px-2 py-1 text-xs text-slate-400 font-bold select-none">
                                ...
                            </span>
                        );
                    }

                    return (
                        <button
                            key={page}
                            className={`px-3 py-1.5 text-xs font-semibold rounded-xl transition-all ${
                                current === page 
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-500/20' 
                                    : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700'
                            }`}
                            onClick={() => Inertia.get(route('dashboard'), { ...getPaginationParams(), page }, { preserveState: false })}
                                        >
                                            {page}
                                        </button>
                                    );
                                });
                            })()}

                            {/* Next Button */}
                            <button
                                className="px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-xl bg-white shadow-sm disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50 text-slate-700 transition-all"
                                disabled={paginationMeta.current_page >= paginationMeta.last_page}
                                onClick={() => Inertia.get(route('dashboard'), { ...getPaginationParams(), page: paginationMeta.current_page + 1 }, { preserveState: false })}
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function useSyncTransactions(transactions, setLocalTransactions, computeRunningBalances, setVisibleIds, mountedRef, prevIdsRef) {
    useEffect(() => {
        const rawList = Array.isArray(transactions) ? transactions : (transactions?.data ?? []);
        const list = [...rawList].slice().reverse();
        const withBalance = computeRunningBalances(list);

        const newIds = [];
        const incomingIds = new Set(withBalance.map((t) => t.id));
        const prevIds = prevIdsRef.current || new Set();

        withBalance.forEach((t) => {
            if (!prevIds.has(t.id)) newIds.push(t.id);
        });

        setLocalTransactions(withBalance);

        if (!mountedRef.current) {
            setVisibleIds(incomingIds);
            mountedRef.current = true;
        } else if (newIds.length > 0) {
            setVisibleIds((prev) => new Set(prev));

            setTimeout(() => {
                setVisibleIds((prev) => {
                    const next = new Set(prev);
                    newIds.forEach((id) => next.add(id));
                    return next;
                });
            }, 40);
        }

        prevIdsRef.current = incomingIds;
    }, [transactions]);
}