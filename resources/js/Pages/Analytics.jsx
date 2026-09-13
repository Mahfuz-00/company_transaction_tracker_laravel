import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Chart as ChartJS, ArcElement, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';
import { Doughnut, Bar } from 'react-chartjs-2';

ChartJS.register(ArcElement, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

export default function Analytics({ auth, totalIn, totalOut, netBalance, monthlySummary }) {
    const pieData = {
        labels: ['Cash In', 'Cash Out'],
        datasets: [
            {
                data: [totalIn, totalOut],
                backgroundColor: ['#22c55e', '#ef4444'],
            },
        ],
    };

    const barData = {
        labels: monthlySummary.map((m) => m.month),
        datasets: [
            {
                label: 'Income',
                data: monthlySummary.map((m) => m.income),
                backgroundColor: '#22c55e',
            },
            {
                label: 'Expense',
                data: monthlySummary.map((m) => m.expense),
                backgroundColor: '#ef4444',
            },
        ],
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Analytics Dashboard</h2>}
        >
            <Head title="Analytics" />

            <div className="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="p-6 bg-white/6 backdrop-blur-md rounded-xl shadow-lg text-center">
                        <p className="text-white/80 text-sm">Total Cash In</p>
                        <p className="text-2xl font-bold text-green-400">${totalIn.toFixed(2)}</p>
                    </div>
                    <div className="p-6 bg-white/6 backdrop-blur-md rounded-xl shadow-lg text-center">
                        <p className="text-white/80 text-sm">Total Cash Out</p>
                        <p className="text-2xl font-bold text-red-400">${totalOut.toFixed(2)}</p>
                    </div>
                    <div className="p-6 bg-white/6 backdrop-blur-md rounded-xl shadow-lg text-center">
                        <p className="text-white/80 text-sm">Net Savings</p>
                        <p className={`text-2xl font-bold ${netBalance >= 0 ? 'text-green-400' : 'text-red-400'}`}>
                            ${netBalance.toFixed(2)}
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="p-6 bg-white/6 backdrop-blur-md rounded-xl shadow-lg flex flex-col items-center">
                        <h3 className="text-md font-bold mb-4 text-white/90">Cash Flow Overview</h3>
                        <div className="w-64 h-64">
                            <Doughnut data={pieData} />
                        </div>
                    </div>
                    <div className="p-6 bg-white/6 backdrop-blur-md rounded-xl shadow-lg">
                        <h3 className="text-md font-bold mb-4 text-white/90">Monthly Trends</h3>
                        <Bar data={barData} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}