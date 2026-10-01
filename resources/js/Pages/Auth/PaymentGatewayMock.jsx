
import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import ThemeProvider from '@/Components/ThemeProvider';

export default function PaymentGatewayMock({ institution, reference }) {
    const [processing, setProcessing] = useState(false);

    const handlePay = () => {
        setProcessing(true);
        router.post(`/onboarding/gateway/${reference}/complete`, {}, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <ThemeProvider>
            <Head title="Secure Payment Gateway" />
            <div className="min-h-screen bg-slate-100 flex flex-col justify-center py-12 px-4 sm:px-6 lg:px-8">
                <div className="max-w-md w-full mx-auto bg-white rounded-2xl shadow-xl border border-slate-200 p-8 text-center space-y-6">
                    <div className="inline-flex items-center justify-center h-16 w-16 rounded-full bg-emerald-100 text-emerald-600 mb-2">
                        <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>

                    <div>
                        <h2 className="text-xl font-bold text-slate-900">Secure Payment Gateway</h2>
                        <p className="text-xs text-slate-500 mt-1">Complete your subscription for {institution.name}</p>
                    </div>

                    <div className="bg-slate-50 rounded-xl p-4 text-left space-y-2 border border-slate-200">
                        <div className="flex justify-between text-xs">
                            <span className="text-slate-500">Invoice Reference:</span>
                            <span className="font-mono font-bold text-slate-800" data-testid="gateway-ref">{reference}</span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-slate-500">Plan:</span>
                            <span className="font-bold text-slate-800">{institution.subscription_plan || 'Standard'}</span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-slate-500">Amount Due:</span>
                            <span className="font-extrabold text-indigo-600">${institution.subscription_amount || '29.00'}</span>
                        </div>
                        <div className="flex justify-between text-xs">
                            <span className="text-slate-500">Gateway:</span>
                            <span className="capitalize font-semibold text-slate-700">{institution.payment_gateway || 'Stripe'}</span>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={handlePay}
                        disabled={processing}
                        data-testid="pay-complete-btn"
                        className="w-full py-3.5 px-4 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-md transition-colors disabled:opacity-50"
                    >
                        {processing ? 'Processing Payment...' : 'Pay & Complete Registration'}
                    </button>

                    <p className="text-[11px] text-slate-400">
                        Simulated secure 256-bit encrypted checkout. No real card will be billed in demo mode.
                    </p>
                </div>
            </div>
        </ThemeProvider>
    );
}
