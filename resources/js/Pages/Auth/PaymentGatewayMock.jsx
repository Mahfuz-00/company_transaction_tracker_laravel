
import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import ThemeProvider from '@/Components/ThemeProvider';

export default function PaymentGatewayMock({ institution, reference, resumedWarning = false }) {
    const [processing, setProcessing] = useState(false);
    const [showWarning, setShowWarning] = useState(Boolean(resumedWarning));

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
                {showWarning && (
                    <div
                        role="dialog"
                        aria-modal="true"
                        data-testid="duplicate-warning-dialog"
                        className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
                    >
                        <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl border border-amber-300 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-600 mb-3">
                                <svg className="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">Previous Incomplete Registration Detected</h3>
                            <p className="mt-3 text-sm text-slate-600 leading-relaxed">
                                You tried to register before. This is your final attempt before previous uncompleted information is permanently purged. Please proceed to payment to activate your account.
                            </p>
                            <button
                                type="button"
                                onClick={() => setShowWarning(false)}
                                data-testid="acknowledge-warning-btn"
                                className="mt-5 w-full rounded-xl bg-amber-600 py-3 px-4 text-sm font-bold text-white shadow-md hover:bg-amber-700 transition-colors"
                            >
                                Acknowledge & Proceed to Payment
                            </button>
                        </div>
                    </div>
                )}
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
