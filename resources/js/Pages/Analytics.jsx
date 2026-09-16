import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useCurrencySettings, formatCurrencyValue } from '@/Utils/useCurrency';
import { 
    Chart as ChartJS, 
    ArcElement, 
    CategoryScale, 
    LinearScale, 
    BarElement, 
    Title, 
    Tooltip, 
    Legend,
    LineElement, 
    PointElement, 
    Filler 
} from 'chart.js';
import { Doughnut, Bar } from 'react-chartjs-2';

ChartJS.register(ArcElement, CategoryScale, LinearScale, BarElement, LineElement, PointElement, Filler, Title, Tooltip, Legend);

export default function Analytics({
    auth,
    totalIn = 0,
    totalOut = 0,
    netBalance = 0,
    monthlySummary = [],
    currentBalance = 0,
    activeFilters = {},
    previousPeriod = {},
    dorm = {},
    mealTrend = [],
    expenseByCategory = [],
    topExpenses = [],
    grouping = 'daily',
}) {
    const [currency] = useCurrencySettings();
    const { data, setData } = useForm({
        period: activeFilters.period || 'current_month',
        from: activeFilters.from || '',
        to: activeFilters.to || '',
    });

    const [viewType, setViewType] = useState('area'); // 'area' or 'bar'
    const chartRef = useRef(null);

    // Keep state in sync when server returns updated filters
    useEffect(() => {
        setData((prev) => ({
            ...prev,
            period: activeFilters.period || 'current_month',
            from: activeFilters.from || '',
            to: activeFilters.to || '',
        }));
    }, [activeFilters]);

    // Handler for quick period select buttons
    const handlePeriodChange = (period) => {
        setData('period', period);
        router.get(
            route('analytics'),
            { period },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    // Handler for custom date range filter
    const handleDateApply = (e) => {
        e.preventDefault();
        router.get(
            route('analytics'),
            { period: 'custom', from: data.from, to: data.to },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    // Pie / Doughnut Data
    const pieData = {
        labels: ['Cash In', 'Cash Out'],
        datasets: [
            {
                data: [Number(totalIn) || 0, Number(totalOut) || 0],
                backgroundColor: ['#10b981', '#f43f5e'],
                hoverBackgroundColor: ['#059669', '#e11d48'],
                borderWidth: 2,
                borderColor: '#ffffff',
            },
        ],
    };

    const labels = monthlySummary.map((m) => m.period || m.month || '');
    const incomes = monthlySummary.map((m) => Number(m.income) || 0);
    const expenses = monthlySummary.map((m) => Number(m.expense) || 0);

    const barData = {
        labels,
        datasets: [
            {
                type: 'line',
                label: 'Income',
                data: incomes,
                borderColor: '#10b981',
                backgroundColor: (ctx) => {
                    const chart = ctx.chart;
                    const { ctx: c, chartArea } = chart;
                    if (!chartArea) return 'rgba(16,185,129,0.1)';
                    const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(16,185,129,0.28)');
                    gradient.addColorStop(1, 'rgba(16,185,129,0.06)');
                    return gradient;
                },
                fill: true,
                tension: 0.3,
                pointRadius: 3,
            },
            {
                type: 'bar',
                label: 'Expense',
                data: expenses,
                backgroundColor: '#f43f5e',
                borderRadius: 6,
            },
        ],
    };

    // Corrected Doughnut Center Text Plugin
    const doughnutCenterPlugin = {
        id: 'doughnutCenter',
        afterDraw(chart) {
            const centerConfig = chart.config.options.plugins?.doughnutCenter;
            if (centerConfig && centerConfig.text) {
                const { ctx, chartArea } = chart;
                if (!chartArea) return;

                const fontStyle = centerConfig.fontStyle || '600';
                const txt = centerConfig.text;
                const color = centerConfig.color || '#374151';

                ctx.save();
                ctx.font = `${fontStyle} 16px Inter, sans-serif`;
                ctx.fillStyle = color;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                const centerX = (chartArea.left + chartArea.right) / 2;
                const centerY = (chartArea.top + chartArea.bottom) / 2;
                ctx.fillText(txt, centerX, centerY);
                ctx.restore();
            }
        },
    };

    const commonChartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    padding: 20,
                    font: { family: 'Inter, sans-serif', size: 12, weight: '500' },
                    color: '#64748b',
                },
            },
            tooltip: {
                backgroundColor: '#0f172a',
                titleFont: { family: 'Inter, sans-serif', size: 13, weight: '600' },
                bodyFont: { family: 'Inter, sans-serif', size: 12 },
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: function (context) {
                        const v = context.parsed.y !== undefined ? context.parsed.y : context.parsed || context.raw || 0;
                        return ` ${context.dataset.label || context.label}: ${formatCurrencyValue(Number(v || 0), currency)}`;
                    },
                },
            },
        },
    };

    /* --- Dorm meal trend: stacked B/L/D bars --- */
    const mealTrendLabels = mealTrend.map((m) => m.period);

    const mealTrendData = {
        labels: mealTrendLabels,
        datasets: [
            {
                label: 'Breakfast',
                data: mealTrend.map((m) => Number(m.breakfast) || 0),
                backgroundColor: '#f59e0b',
                borderRadius: 3,
                stack: 'meals',
            },
            {
                label: 'Lunch',
                data: mealTrend.map((m) => Number(m.lunch) || 0),
                backgroundColor: '#0ea5e9',
                borderRadius: 3,
                stack: 'meals',
            },
            {
                label: 'Dinner',
                data: mealTrend.map((m) => Number(m.dinner) || 0),
                backgroundColor: '#8b5cf6',
                borderRadius: 3,
                stack: 'meals',
            },
        ],
    };

    const mealTrendOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    padding: 16,
                    font: { family: 'Inter, sans-serif', size: 11, weight: '500' },
                    color: '#64748b',
                },
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    // Plain counts - these are meals, not money.
                    label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y} meals`,
                },
            },
        },
        scales: {
            x: {
                stacked: true,
                grid: { display: false },
                ticks: { font: { size: 10 }, color: '#94a3b8' },
            },
            y: {
                stacked: true,
                beginAtZero: true,
                grid: { color: '#f1f5f9' },
                ticks: { font: { size: 10 }, color: '#94a3b8', precision: 0 },
            },
        },
    };

    /* --- Expense by category --- */
    const categoryPalette = [
        '#6366f1', '#f43f5e', '#10b981', '#f59e0b',
        '#0ea5e9', '#8b5cf6', '#14b8a6', '#64748b',
    ];

    const categoryData = {
        labels: expenseByCategory.map((e) => e.category),
        datasets: [
            {
                data: expenseByCategory.map((e) => Number(e.total) || 0),
                backgroundColor: categoryPalette.slice(0, expenseByCategory.length),
                borderWidth: 2,
                borderColor: '#ffffff',
            },
        ],
    };

    const categoryTotal = expenseByCategory.reduce((sum, e) => sum + Number(e.total || 0), 0);

    const barOptions = {
        ...commonChartOptions,
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { family: 'Inter, sans-serif', size: 11 }, color: '#94a3b8' },
            },
            y: {
                border: { dash: [4, 4] },
                grid: { color: '#f1f5f9' },
                ticks: {
                    font: { family: 'Inter, sans-serif', size: 11 },
                    color: '#94a3b8',
                    callback: function (value) {
                        return formatCurrencyValue(Number(value || 0), currency);
                    },
                },
            },
        },
        animation: { duration: 600, easing: 'easeOutCubic' },
    };

    const doughnutOptions = {
        ...commonChartOptions,
        cutout: '75%',
        plugins: {
            ...commonChartOptions.plugins,
            legend: { display: false },
            doughnutCenter: {
                text: formatCurrencyValue(Number(totalOut || 0), currency),
                color: '#0f172a',
            },
        },
    };

    return (
        <AuthenticatedLayout
            user={auth?.user}
            header={<h2 className="font-bold text-2xl text-slate-800 tracking-tight">Analytics Dashboard</h2>}
        >
            <Head title="Analytics" />

            <div className="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full space-y-8">
                {/* Filter Bar */}
                <div className="flex flex-col md:flex-row items-start md:items-center gap-4 justify-between bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="inline-flex rounded-lg border border-slate-200 p-1 bg-slate-50" role="group">
                            {['current_month', 'last_month', 'last_3_months', 'ytd'].map((p) => {
                                const labelsMap = {
                                    current_month: 'Current Month',
                                    last_month: 'Last Month',
                                    last_3_months: 'Last 3 Months',
                                    ytd: 'YTD',
                                };
                                return (
                                    <button
                                        key={p}
                                        type="button"
                                        onClick={() => handlePeriodChange(p)}
                                        className={`px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
                                            data.period === p
                                                ? 'bg-indigo-600 text-white shadow-sm'
                                                : 'text-slate-600 hover:text-slate-900'
                                        }`}
                                    >
                                        {labelsMap[p]}
                                    </button>
                                );
                            })}
                        </div>

                        <form onSubmit={handleDateApply} className="flex items-center gap-2">
                            <input
                                type="date"
                                value={data.from}
                                onChange={(e) => setData('from', e.target.value)}
                                className="border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                            <span className="text-slate-400 text-xs">to</span>
                            <input
                                type="date"
                                value={data.to}
                                onChange={(e) => setData('to', e.target.value)}
                                className="border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                            <button
                                type="submit"
                                className="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-xs transition-colors"
                            >
                                Apply
                            </button>
                        </form>
                    </div>

                    {/* Wider View Selector */}
                    <div className="flex items-center gap-2 w-full md:w-auto">
                        <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider whitespace-nowrap">
                            View Mode:
                        </label>
                        <select
                            value={viewType}
                            onChange={(e) => setViewType(e.target.value)}
                            className="w-48 border border-slate-300 rounded-lg px-3 py-1.5 text-xs font-medium text-slate-700 bg-white focus:ring-indigo-500 focus:border-indigo-500 shadow-sm cursor-pointer"
                        >
                            <option value="area">Area + Bars</option>
                            <option value="bar">Stacked Bars</option>
                        </select>
                    </div>
                </div>

                {/* Metric Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                    {/* Total Cash In */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition-all flex items-center justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Cash In</p>
                            <div className="flex items-center gap-3">
                                <h3 className="text-2xl font-extrabold text-emerald-600 mt-1">
                                    {formatCurrencyValue(Number(totalIn || 0), currency)}
                                </h3>
                                {previousPeriod && previousPeriod.totalIn !== undefined && (
                                    <span className={`text-xs font-medium px-2 py-1 rounded ${Number(previousPeriod.totalIn) < Number(totalIn) ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>
                                        {(() => {
                                            const prev = Number(previousPeriod.totalIn || 0);
                                            const curr = Number(totalIn || 0);
                                            if (prev === 0) return '–';
                                            const p = Math.round(((curr - prev) / Math.abs(prev)) * 100);
                                            return `${p >= 0 ? '+' : ''}${p}%`;
                                        })()}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center border border-emerald-100/60">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                        </div>
                    </div>

                    {/* Total Cash Out */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition-all flex items-center justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Cash Out</p>
                            <div className="flex items-center gap-3">
                                <h3 className="text-2xl font-extrabold text-rose-600 mt-1">
                                    {formatCurrencyValue(Number(totalOut || 0), currency)}
                                </h3>
                                {previousPeriod && previousPeriod.totalOut !== undefined && (
                                    <span className={`text-xs font-medium px-2 py-1 rounded ${Number(previousPeriod.totalOut) < Number(totalOut) ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>
                                        {(() => {
                                            const prev = Number(previousPeriod.totalOut || 0);
                                            const curr = Number(totalOut || 0);
                                            if (prev === 0) return '–';
                                            const p = Math.round(((curr - prev) / Math.abs(prev)) * 100);
                                            return `${p >= 0 ? '+' : ''}${p}%`;
                                        })()}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="w-12 h-12 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center border border-rose-100/60">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                            </svg>
                        </div>
                    </div>

                    {/* Net Savings */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition-all flex items-center justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Net Savings</p>
                            <h3 className={`text-2xl font-extrabold mt-1 ${netBalance >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                {formatCurrencyValue(Number(netBalance || 0), currency)}
                            </h3>
                        </div>
                        <div className={`w-12 h-12 rounded-xl flex items-center justify-center border ${
                            netBalance >= 0 
                                ? 'bg-emerald-50 text-emerald-600 border-emerald-100/60' 
                                : 'bg-rose-50 text-rose-600 border-rose-100/60'
                        }`}>
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>

                    {/* Current Overall Balance */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition-all flex items-center justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Current Balance</p>
                            <h3 className={`text-2xl font-extrabold mt-1 ${currentBalance >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                {formatCurrencyValue(Number(currentBalance || 0), currency)}
                            </h3>
                        </div>
                        <div className={`w-12 h-12 rounded-xl flex items-center justify-center border ${
                            currentBalance >= 0 ? 'bg-emerald-50 text-emerald-600 border-emerald-100/60' : 'bg-rose-50 text-rose-600 border-rose-100/60'
                        }`}>
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2" />
                            </svg>
                        </div>
                    </div>
                </div>

                {/* Dorm-wide metrics */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pool Balance</p>
                        <h3 className={`mt-1 text-2xl font-extrabold ${(dorm.pool_balance ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                            {formatCurrencyValue(Number(dorm.pool_balance || 0), currency)}
                        </h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {formatCurrencyValue(Number(dorm.deposits || 0), currency)} in ·{' '}
                            {formatCurrencyValue(Number(dorm.expenses || 0), currency)} out
                        </p>
                    </div>

                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Meals</p>
                        <h3 className="mt-1 text-2xl font-extrabold text-indigo-600">
                            {dorm.meals ?? 0}
                        </h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {dorm.breakfast ?? 0}B · {dorm.lunch ?? 0}L · {dorm.dinner ?? 0}D
                        </p>
                    </div>

                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Cost / Meal</p>
                        <h3 className="mt-1 text-2xl font-extrabold text-slate-900">
                            {formatCurrencyValue(Number(dorm.cost_per_meal || 0), currency)}
                        </h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {formatCurrencyValue(Number(dorm.meals || 0) * Number(dorm.cost_per_meal || 0), currency)} total cost
                        </p>
                    </div>

                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Active Students</p>
                        <h3 className="mt-1 text-2xl font-extrabold text-slate-900">{dorm.active_students ?? 0}</h3>
                        <p className="mt-0.5 text-xs text-slate-400">Currently sharing meals</p>
                    </div>
                </div>

                {/* Meal trend + expense breakdown */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
                    <div className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-sm lg:col-span-3">
                        <div>
                            <h3 className="text-lg font-bold tracking-tight text-slate-900">Meal Consumption Trend</h3>
                            <p className="mt-1 text-xs text-slate-500">
                                Meals eaten per {grouping} period, split by type
                            </p>
                        </div>
                        <div className="my-4 h-64">
                            {mealTrend.length > 0 ? (
                                <Bar data={mealTrendData} options={mealTrendOptions} />
                            ) : (
                                <div className="flex h-full items-center justify-center text-xs italic text-slate-400">
                                    No meal entries recorded in this period.
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-sm lg:col-span-2">
                        <div>
                            <h3 className="text-lg font-bold tracking-tight text-slate-900">Where Money Went</h3>
                            <p className="mt-1 text-xs text-slate-500">Expenses grouped by category</p>
                        </div>

                        {expenseByCategory.length > 0 ? (
                            <>
                                <div className="relative my-4 h-48">
                                    <Doughnut
                                        data={categoryData}
                                        options={{
                                            ...doughnutOptions,
                                            plugins: {
                                                ...doughnutOptions.plugins,
                                                doughnutCenter: {
                                                    text: formatCurrencyValue(categoryTotal, currency),
                                                    color: '#0f172a',
                                                },
                                            },
                                        }}
                                        plugins={[doughnutCenterPlugin]}
                                    />
                                </div>

                                <ul className="space-y-2">
                                    {expenseByCategory.slice(0, 5).map((row, index) => {
                                        const pct = categoryTotal > 0
                                            ? Math.round((Number(row.total) / categoryTotal) * 100)
                                            : 0;

                                        return (
                                            <li key={row.category} className="space-y-1">
                                                <div className="flex items-center justify-between text-xs">
                                                    <span className="flex items-center gap-2 font-semibold text-slate-700">
                                                        <span
                                                            className="h-2.5 w-2.5 rounded-full"
                                                            style={{ backgroundColor: categoryPalette[index % categoryPalette.length] }}
                                                        />
                                                        {row.category}
                                                    </span>
                                                    <span className="font-bold text-slate-800">
                                                        {formatCurrencyValue(Number(row.total), currency)}
                                                        <span className="ml-1.5 font-normal text-slate-400">{pct}%</span>
                                                    </span>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </>
                        ) : (
                            <div className="flex h-64 items-center justify-center text-xs italic text-slate-400">
                                No expenses in this period.
                            </div>
                        )}
                    </div>
                </div>

                {/* Largest expenses */}
                {topExpenses.length > 0 && (
                    <div className="overflow-hidden rounded-2xl border-slate-200/80 bg-white shadow-sm">
                        <div className="border-b border-slate-100 px-6 py-4">
                            <h3 className="text-base font-bold text-slate-900">Largest Expenses</h3>
                            <p className="text-xs text-slate-500">Biggest single outgoings in this period</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        <th className="px-6 py-2.5">Date</th>
                                        <th className="px-6 py-2.5">Description</th>
                                        <th className="px-6 py-2.5">Paid To</th>
                                        <th className="px-6 py-2.5">Category</th>
                                        <th className="px-6 py-2.5 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-sm">
                                    {topExpenses.map((tx) => (
                                        <tr key={tx.id} className="transition-colors hover:bg-slate-50/60">
                                            <td className="whitespace-nowrap px-6 py-3 text-xs text-slate-500">{tx.date}</td>
                                            <td className="px-6 py-3 font-medium text-slate-800">{tx.item}</td>
                                            <td className="px-6 py-3 text-xs text-slate-500">
                                                {tx.payee || <span className="text-slate-300">—</span>}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-slate-500">
                                                {tx.category ? (
                                                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-600">
                                                        {tx.category}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-300">—</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-3 text-right font-bold text-rose-600">
                                                −{formatCurrencyValue(Number(tx.amount), currency)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Charts Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
                    {/* Cash Flow Distribution Doughnut */}
                    <div className="lg:col-span-2 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <div>
                            <h3 className="text-lg font-bold text-slate-900 tracking-tight">Cash Flow Distribution</h3>
                            <p className="text-xs text-slate-500 mt-1">Ratio of total income against expenses</p>
                        </div>
                        <div className="w-full h-64 relative flex items-center justify-center my-4">
                            <Doughnut data={pieData} options={doughnutOptions} plugins={[doughnutCenterPlugin]} />
                        </div>
                    </div>

                    {/* Monthly Financial Performance Bar */}
                    <div className="lg:col-span-3 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <div>
                            <h3 className="text-lg font-bold text-slate-900 tracking-tight">Monthly Trends</h3>
                            <p className="text-xs text-slate-500 mt-1">Comparative breakdown across months</p>
                        </div>
                        <div className="w-full h-64 my-4">
                            {viewType === 'area' ? (
                                <Bar key="area-bar-chart" data={barData} options={barOptions} ref={chartRef} />
                            ) : (
                                <Bar
                                    key="stacked-bar-chart"
                                    data={{
                                        labels,
                                        datasets: [
                                            { label: 'Income', data: incomes, backgroundColor: '#10b981', borderRadius: 4 },
                                            { label: 'Expense', data: expenses, backgroundColor: '#f43f5e', borderRadius: 4 },
                                        ],
                                    }}
                                    options={{
                                        ...barOptions,
                                        scales: {
                                            x: { stacked: true, grid: { display: false }, ticks: { font: { family: 'Inter, sans-serif', size: 11 }, color: '#94a3b8' } },
                                            y: {
                                                stacked: true,
                                                border: { dash: [4, 4] },
                                                grid: { color: '#f1f5f9' },
                                                ticks: {
                                                    callback: (v) => formatCurrencyValue(Number(v || 0), currency),
                                                    color: '#94a3b8',
                                                    font: { family: 'Inter, sans-serif', size: 11 },
                                                },
                                            },
                                        },
                                    }}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}