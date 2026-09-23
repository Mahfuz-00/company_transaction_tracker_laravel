import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import useMoney from '@/Utils/useMoney';
import MetricCard, { pctChange } from '@/Components/Analytics/MetricCard';
import ForecastPanel from '@/Components/Analytics/ForecastPanel';
import SubsidyTrackingPanel from '@/Components/Analytics/SubsidyTrackingPanel';
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

/**
 * Analytics - the money and meal intelligence dashboard.
 *
 * PURPOSE
 * One page that answers "where did the money go, and how are meals trending?".
 * It renders a filter bar, four cash metric cards, four institution metrics, a
 * subsidy-tracking panel, a three-month forecast, and the visualisations listed
 * below. Every figure flows through useMoney(), which applies the admin's global
 * abbreviation threshold (1,234 -> 1.23 K) so the whole page scales uniformly.
 *
 * PROPS (from the Laravel controller)
 *  - auth: shared auth bag (auth.user feeds the layout header).
 *  - totalIn / totalOut / netBalance / currentBalance: the headline cash values.
 *  - monthlySummary: [{ period|month, income, expense }] for the trends chart.
 *  - activeFilters / previousPeriod: current filter echo + prior-period totals
 *    used to compute the % trend chips on the metric cards.
 *  - dorm: institution metrics { pool_balance, deposits, expenses, meals,
 *    breakfast, lunch, dinner, cost_per_meal, active_students }.
 *  - mealTrend: [{ period, breakfast, lunch, dinner }] for the meal-trend chart.
 *  - expenseByCategory: [{ category, total }] for the doughnut + legend list.
 *  - topExpenses: the largest single outgoings (table).
 *  - grouping: 'daily' | 'monthly' - labels the meal-trend x-axis text.
 *  - month / months: the month selector's option list and selected value.
 *  - subsidyTracking / forecast: payloads passed whole into their panels.
 *
 * CHART MAP (Chart.js via react-chartjs-2)
 *  - Meal Consumption Trend (Bar, stacked): breakfast/lunch/dinner per period,
 *    plain meal counts - not money.
 *  - Where Money Went (Doughnut): expense totals per category, with the total
 *    drawn in the middle by a custom plugin.
 *  - Cash Flow Distribution (Doughnut): total income vs total expense split.
 *  - Monthly Trends (Bar): income as a filled line and expense as bars; the
 *    view toggle swaps this for true stacked bars.
 *
 * FLOW
 *  - Period / custom date range (filter bar): handlePeriodChange() and
 *    handleDateApply() call router.get(route('analytics'), {...}, {
 *    preserveState, preserveScroll, replace }) - a partial Inertia visit that
 *    keeps the filters in sync via the useEffect on activeFilters.
 *  - Month + chart-view selects do the same router.get or a local setState.
 *  - Data objects (pieData, barData, mealTrendData, categoryData) are rebuilt
 *    each render from props, which is the idiomatic react-chartjs-2 pattern:
 *    you pass `data` + `options` and the library diffs them for you.
 */
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
    month = '',
    months = [],
    monthSnapshot = {},
    subsidyTracking = {},
    forecast = {},
}) {
    // money() routes through the GLOBAL threshold system, so every figure on
    // this page (cards, charts, tables) adapts uniformly (1,234 -> 1.23 K) the
    // moment the admin's Abbreviation Threshold is crossed.
    const money = useMoney();
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
                        return ` ${context.dataset.label || context.label}: ${money(Number(v || 0), false)}`;
                    },
                },
            },
        },
    };

    /* --- Institution meal trend: stacked B/L/D bars --- */
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
                        return money(Number(value || 0), false);
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
                text: money(Number(totalOut || 0), false),
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
                {/* Filter Bar - grouped into labelled sections so it reads as
                    structured controls rather than a loose row of widgets. */}
                <div className="rounded-xl border-slate-200/80 bg-white p-4 shadow-sm">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        {/* Section 1: reporting period. */}
                        <div className="flex flex-col gap-2">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                Reporting Period
                            </span>
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="inline-flex rounded-lg border-slate-200 bg-slate-50 p-1" role="group" aria-label="Quick period">
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
                                                className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-colors ${
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
                                        aria-label="From date"
                                        value={data.from}
                                        onChange={(e) => setData('from', e.target.value)}
                                        className="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <span className="text-xs text-slate-400">to</span>
                                    <input
                                        type="date"
                                        aria-label="To date"
                                        value={data.to}
                                        onChange={(e) => setData('to', e.target.value)}
                                        className="rounded-lg border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <button
                                        type="submit"
                                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-indigo-700"
                                    >
                                        Apply
                                    </button>
                                </form>
                            </div>
                        </div>

                        {/* Section 2: display controls. */}
                        <div className="flex flex-col gap-2">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                Display
                            </span>
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="flex items-center gap-2">
                                    <label htmlFor="analytics-month" className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                        Month
                                    </label>
                                    <select
                                        id="analytics-month"
                                        value={month}
                                        onChange={(e) => router.get(route('analytics'), { month: e.target.value }, { preserveState: true, preserveScroll: true, replace: true })}
                                        className="w-44 cursor-pointer rounded-lg border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-sm focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                                    >
                                        {(months || []).map((m) => (
                                            <option key={m.value} value={m.value}>
                                                {m.label}{m.current ? ' (current)' : ''}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="flex items-center gap-2">
                                    <label htmlFor="analytics-view" className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                        Chart
                                    </label>
                                    <select
                                        id="analytics-view"
                                        value={viewType}
                                        onChange={(e) => setViewType(e.target.value)}
                                        className="w-40 cursor-pointer rounded-lg border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="area">Area + Bars</option>
                                        <option value="bar">Stacked Bars</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Metric summary cards. Icon tiles are fixed-size so a large
                {/* Metric summary cards. The trend chip sits right after the
                    title, the number stands alone, and the icon is a fixed
                    square pinned top-right - so a large figure can never
                    squash the icon or shift the indicator. */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        label="Total Cash In"
                        value={money(totalIn, false)}
                        tone="emerald"
                        trend={pctChange(previousPeriod?.totalIn, totalIn)}
                        hint="Deposits and other cash received"
                        icon={
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                        }
                    />

                    <MetricCard
                        label="Total Cash Out"
                        value={money(totalOut, false)}
                        tone="rose"
                        trend={pctChange(previousPeriod?.totalOut, totalOut)}
                        hint="Expenses and other cash paid out"
                        icon={
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                            </svg>
                        }
                    />

                    <MetricCard
                        label="Net Savings"
                        value={money(netBalance, false)}
                        tone={netBalance >= 0 ? 'emerald' : 'rose'}
                        hint="Cash in minus cash out"
                        icon={
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        }
                    />

                    <MetricCard
                        label="Current Balance"
                        value={money(currentBalance, false)}
                        tone={currentBalance >= 0 ? 'accent' : 'rose'}
                        hint="Live balance across all accounts"
                        icon={
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2" />
                            </svg>
                        }
                    />
                </div>

                {/* Institution-wide metrics */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pool Balance</p>
                        <h3 className={`mt-1 text-2xl font-extrabold ${(dorm.pool_balance ?? 0) >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                            {money(dorm.pool_balance ?? 0, false)}
                        </h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {money(dorm.deposits ?? 0)} in · {money(dorm.expenses ?? 0)} out
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
                            {money(dorm.cost_per_meal ?? 0, false)}
                        </h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {money(Number(dorm.meals || 0) * Number(dorm.cost_per_meal || 0))} total cost
                        </p>
                    </div>

                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Active Members</p>
                        <h3 className="mt-1 text-2xl font-extrabold text-slate-900">{dorm.active_students ?? 0}</h3>
                        <p className="mt-0.5 text-xs text-slate-400">Currently sharing meals</p>
                    </div>
                </div>

                {/* Subsidy tracking: each funder's target share vs what was
                    actually recorded this month. */}
                <SubsidyTrackingPanel tracking={subsidyTracking} money={money} />

                {/* The three-month predictive engine. */}
                <ForecastPanel forecast={forecast} money={money} />

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
                                                    text: money(categoryTotal, false),
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
                                                        {money(Number(row.total), false)}
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
                                                −{money(Number(tx.amount), false)}
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
                                                    callback: (v) => money(Number(v || 0), false),
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
