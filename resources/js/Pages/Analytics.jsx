import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useCurrencySettings, formatCurrencyValue } from '@/Utils/useCurrency';
import { 
    Chart as ChartJS, 
    ArcElement, 
    CategoryScale, 
    LinearScale, 
    BarElement, 
    Title, 
    Tooltip, 
    Legend 
} from 'chart.js';
import { Doughnut, Bar } from 'react-chartjs-2';

ChartJS.register(ArcElement, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

export default function Analytics({ auth, totalIn, totalOut, netBalance, monthlySummary }) {
    const [currency] = useCurrencySettings();

    // Chart.js global style enhancements
    const pieData = {
        labels: ['Cash In', 'Cash Out'],
        datasets: [
            {
                data: [totalIn, totalOut],
                backgroundColor: ['#10b981', '#f43f5e'],
                hoverBackgroundColor: ['#059669', '#e11d48'],
                borderWidth: 2,
                borderColor: '#ffffff',
            },
        ],
    };

    const barData = {
        labels: monthlySummary.map((m) => m.month),
        datasets: [
            {
                label: 'Income',
                data: monthlySummary.map((m) => m.income),
                backgroundColor: '#10b981',
                borderRadius: 6,
            },
            {
                label: 'Expense',
                data: monthlySummary.map((m) => m.expense),
                backgroundColor: '#f43f5e',
                borderRadius: 6,
            },
        ],
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
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-bold text-2xl text-slate-800 tracking-tight">Analytics Dashboard</h2>}
        >
            <Head title="Analytics" />

            <div className="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full space-y-8">
                
                {/* Metric Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Total Cash In */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm hover:shadow-md transition-all flex items-center justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Cash In</p>
                            <h3 className="text-2xl font-extrabold text-emerald-600 mt-1">
                                {formatCurrencyValue(Number(totalIn || 0), currency)}
                            </h3>
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
                            <h3 className="text-2xl font-extrabold text-rose-600 mt-1">
                                {formatCurrencyValue(Number(totalOut || 0), currency)}
                            </h3>
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
                </div>

                {/* Charts Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
                    {/* Cash Flow Distribution Doughnut */}
                    <div className="lg:col-span-2 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <div>
                            <h3 className="text-lg font-bold text-slate-900 tracking-tight">Cash Flow Distribution</h3>
                            <p className="text-xs text-slate-500 mt-1">Ratio of total income against expenses</p>
                        </div>
                        <div className="w-full h-64 relative flex items-center justify-center my-4">
                            <Doughnut data={pieData} options={commonChartOptions} />
                        </div>
                    </div>

                    {/* Monthly Financial Performance Bar */}
                    <div className="lg:col-span-3 bg-white rounded-2xl p-6 border border-slate-200/80 shadow-sm flex flex-col justify-between">
                        <div>
                            <h3 className="text-lg font-bold text-slate-900 tracking-tight">Monthly Trends</h3>
                            <p className="text-xs text-slate-500 mt-1">Comparative breakdown across months</p>
                        </div>
                        <div className="w-full h-64 my-4">
                            <Bar data={barData} options={barOptions} />
                        </div>
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}