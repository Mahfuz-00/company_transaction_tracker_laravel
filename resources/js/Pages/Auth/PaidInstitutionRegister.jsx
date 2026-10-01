import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import ThemeProvider from '@/Components/ThemeProvider';

export default function PaidInstitutionRegister({ selectedPlan, plans = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        institution_name: '',
        institution_type: 'general_mess',
        admin_name: '',
        email: '',
        password: '',
        plan_id: selectedPlan?.id || plans[0]?.id || '',
        payment_gateway: 'stripe',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/onboarding/register');
    };

    return (
        <ThemeProvider>
            <Head title="Register Your Institution" />
            <div className="min-h-screen bg-slate-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
                <div className="sm:mx-auto sm:w-full sm:max-w-md text-center">
                    <h2 className="text-3xl font-extrabold text-slate-900">Register Your Institution</h2>
                    <p className="mt-2 text-sm text-slate-600">
                        Create an administrator account and set up your institution workspace.
                    </p>
                </div>

                <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-xl">
                    <div className="bg-white py-8 px-6 shadow-md rounded-2xl sm:px-10 border border-slate-200">
                        <form onSubmit={submit} className="space-y-5">
                            <div>
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Institution Name</label>
                                <input
                                    type="text"
                                    value={data.institution_name}
                                    onChange={(e) => setData('institution_name', e.target.value)}
                                    placeholder="e.g. Apex Hall / Silicon Mess"
                                    data-testid="paid-inst-name"
                                    required
                                    className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                />
                                {errors.institution_name && <p className="text-xs text-rose-500 mt-1">{errors.institution_name}</p>}
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Institution Type</label>
                                    <select
                                        value={data.institution_type}
                                        onChange={(e) => setData('institution_type', e.target.value)}
                                        data-testid="paid-inst-type"
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                    >
                                        <option value="general_mess">General Mess</option>
                                        <option value="university_hall">University / College Hall</option>
                                        <option value="corporate_cafeteria">Corporate / Office Cafeteria</option>
                                        <option value="hostel">Hostel / Dormitory</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Selected Plan</label>
                                    <select
                                        value={data.plan_id}
                                        onChange={(e) => setData('plan_id', e.target.value)}
                                        data-testid="paid-inst-plan"
                                        className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                    >
                                        {plans.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name} (${p.monthly_price}/mo)</option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div className="border-t border-slate-100 pt-4">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600 mb-3">Administrator Account Details</h4>
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Admin Full Name</label>
                                        <input
                                            type="text"
                                            value={data.admin_name}
                                            onChange={(e) => setData('admin_name', e.target.value)}
                                            placeholder="John Doe"
                                            data-testid="paid-admin-name"
                                            required
                                            className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Email Address (Login)</label>
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            placeholder="admin@institution.com"
                                            data-testid="paid-admin-email"
                                            required
                                            className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                        />
                                        {errors.email && <p className="text-xs text-rose-500 mt-1">{errors.email}</p>}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Password</label>
                                        <input
                                            type="password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            placeholder="••••••••"
                                            data-testid="paid-admin-password"
                                            required
                                            className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-700">Payment Gateway</label>
                                <select
                                    value={data.payment_gateway}
                                    onChange={(e) => setData('payment_gateway', e.target.value)}
                                    data-testid="paid-gateway-select"
                                    className="mt-1 block w-full rounded-xl border-slate-300 text-sm"
                                >
                                    <option value="stripe">Stripe (Credit / Debit Card)</option>
                                    <option value="sslcommerz">SSLCommerz (Mobile Banking & Cards)</option>
                                    <option value="bkash">bKash</option>
                                </select>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                data-testid="paid-submit-btn"
                                className="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-sm text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors disabled:opacity-50"
                            >
                                {processing ? 'Setting up...' : 'Proceed to Payment Gateway →'}
                            </button>

                            <p className="text-center text-xs text-slate-500 mt-3">
                                Already registered?{' '}
                                <Link href={route('login')} className="font-semibold text-indigo-600 hover:text-indigo-500">
                                    Log in
                                </Link>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </ThemeProvider>
    );
}
