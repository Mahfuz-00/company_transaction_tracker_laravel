import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import useCurrencySettings from '@/Utils/useCurrency';
import Button from '@/Components/UI/Button';

export default function AddTransaction({ auth }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        item: '',
        by_whom: '',
        type: 'in',
        amount: '',
        category: '',
        payment_method: 'Cash',
    });

    const [alert, setAlert] = useState(null);
    const [currencySettings] = useCurrencySettings();

    const displayCurrencySymbol = (currencySettings && (currencySettings.symbol || currencySettings.sign)) 
        ? (currencySettings.symbol || currencySettings.sign) 
        : '$';

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('transactions.store'), {
            onSuccess: () => {
                reset();
                setAlert({ type: 'success', message: 'Transaction logged successfully.' });
                setTimeout(() => setAlert(null), 3000);
            },
            onError: () => setAlert({ type: 'error', message: 'Failed to save transaction.' }),
        });
    };

    return (
        <AuthenticatedLayout 
            user={auth.user} 
            header={<h2 className="font-bold text-2xl text-slate-800 tracking-tight">Add Transaction</h2>}
        >
            <Head title="Add Transaction" />

            <div className="py-8 px-4 sm:px-6 lg:px-8 max-w-4xl mx-auto w-full">
                {/* Main Card */}
                <div className="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-6 sm:p-8 transition-all">
                    
                    {/* Header Section */}
                    <div className="mb-8 pb-5 border-b border-slate-100 flex items-center justify-between">
                        <div>
                            <h3 className="text-xl font-bold text-slate-900 tracking-tight">
                                New Transaction Details
                            </h3>
                            <p className="text-sm text-slate-500 mt-1">
                                Record income or expenses into your ledger system.
                            </p>
                        </div>
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-100">
                            Ledger Entry
                        </span>
                    </div>

                    <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                        
                        {/* Transaction Type Radio Options */}
                        <div className="md:col-span-2">
                            <label className="text-sm font-semibold text-slate-700 mb-2.5 block">
                                Transaction Type <span className="text-rose-500">*</span>
                            </label>
                            <div className="flex items-center gap-6">
                                <label className="inline-flex items-center gap-2.5 cursor-pointer select-none">
                                    <input 
                                        type="radio" 
                                        name="type" 
                                        value="in" 
                                        checked={data.type === 'in'} 
                                        onChange={() => setData('type', 'in')} 
                                        disabled={processing} 
                                        className="w-4 h-4 text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer"
                                    />
                                    <span className="text-sm font-semibold text-emerald-700">Cash In (+)</span>
                                </label>

                                <label className="inline-flex items-center gap-2.5 cursor-pointer select-none">
                                    <input 
                                        type="radio" 
                                        name="type" 
                                        value="out" 
                                        checked={data.type === 'out'} 
                                        onChange={() => setData('type', 'out')} 
                                        disabled={processing} 
                                        className="w-4 h-4 text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer"
                                    />
                                    <span className="text-sm font-semibold text-rose-700">Cash Out (-)</span>
                                </label>
                            </div>
                            {errors.type && <span className="text-rose-600 text-xs mt-1.5 font-medium block">{errors.type}</span>}
                        </div>

                        {/* Item Description */}
                        <div>
                            <label className="text-sm font-semibold text-slate-700 mb-1.5 block">
                                Item Description <span className="text-rose-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                required 
                                placeholder="e.g., Office Supplies" 
                                className={`w-full border rounded-xl shadow-sm px-4 h-11 text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all ${
                                    errors.item ? 'border-rose-300 bg-rose-50/30' : 'border-slate-300 bg-white'
                                }`}
                                value={data.item} 
                                onChange={(e) => setData('item', e.target.value)} 
                                disabled={processing} 
                            />
                            {errors.item && <span className="text-rose-600 text-xs mt-1.5 font-medium block">{errors.item}</span>}
                        </div>

                        {/* By Whom */}
                        <div>
                            <label className="text-sm font-semibold text-slate-700 mb-1.5 block">
                                By Whom <span className="text-rose-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                required 
                                placeholder="e.g., Supplier / Person Name" 
                                className={`w-full border rounded-xl shadow-sm px-4 h-11 text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all ${
                                    errors.by_whom ? 'border-rose-300 bg-rose-50/30' : 'border-slate-300 bg-white'
                                }`}
                                value={data.by_whom} 
                                onChange={(e) => setData('by_whom', e.target.value)} 
                                disabled={processing} 
                            />
                            {errors.by_whom && <span className="text-rose-600 text-xs mt-1.5 font-medium block">{errors.by_whom}</span>}
                        </div>

                        {/* Amount Field with Prefix */}
                        <div>
                            <label className="text-sm font-semibold text-slate-700 mb-1.5 block">
                                Amount <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative rounded-xl shadow-sm">
                                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500 font-semibold text-sm">
                                    {displayCurrencySymbol}
                                </div>
                                <input 
                                    type="number" 
                                    step="0.01" 
                                    required 
                                    placeholder="0.00" 
                                    className={`w-full border rounded-xl pl-9 pr-4 h-11 text-slate-900 font-medium placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all ${
                                        errors.amount ? 'border-rose-300 bg-rose-50/30' : 'border-slate-300 bg-white'
                                    }`}
                                    value={data.amount} 
                                    min="0.01" 
                                    onChange={(e) => { 
                                        let v = e.target.value; 
                                        if (typeof v === 'string' && v.startsWith('-')) v = v.slice(1); 
                                        setData('amount', v); 
                                    }} 
                                    disabled={processing} 
                                />
                            </div>
                            {errors.amount && <span className="text-rose-600 text-xs mt-1.5 font-medium block">{errors.amount}</span>}
                        </div>

                        {/* Category */}
                        <div>
                            <label className="text-sm font-semibold text-slate-700 mb-1.5 block">
                                Category <span className="text-rose-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                required 
                                placeholder="e.g., Utilities" 
                                className={`w-full border rounded-xl shadow-sm px-4 h-11 text-slate-900 placeholder-slate-400 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all ${
                                    errors.category ? 'border-rose-300 bg-rose-50/30' : 'border-slate-300 bg-white'
                                }`}
                                value={data.category} 
                                onChange={(e) => setData('category', e.target.value)} 
                                disabled={processing} 
                            />
                            {errors.category && <span className="text-rose-600 text-xs mt-1.5 font-medium block">{errors.category}</span>}
                        </div>

                        {/* Payment Method */}
                        <div className="md:col-span-2">
                            <label className="text-sm font-semibold text-slate-700 mb-1.5 block">
                                Payment Method <span className="text-rose-500">*</span>
                            </label>
                            <select 
                                required 
                                className={`w-full border rounded-xl shadow-sm px-4 h-11 text-slate-900 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all ${
                                    errors.payment_method ? 'border-rose-300 bg-rose-50/30' : 'border-slate-300 bg-white'
                                }`}
                                value={data.payment_method} 
                                onChange={(e) => setData('payment_method', e.target.value)} 
                                disabled={processing}
                            >
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                                <option value="Mobile Banking">Mobile Banking</option>
                            </select>
                            {errors.payment_method && <span className="text-rose-600 text-xs mt-1.5 font-medium block">{errors.payment_method}</span>}
                        </div>

                        {/* Submit Button */}
                        <div className="pt-4 md:col-span-2">
                            <Button 
                                type="submit" 
                                disabled={processing} 
                                className="w-full h-11 text-base font-semibold bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white rounded-xl shadow-sm hover:shadow transition-all flex items-center justify-center gap-2"
                            >
                                {processing ? (
                                    <>
                                        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Saving Transaction...
                                    </>
                                ) : 'Save Transaction'}
                            </Button>
                        </div>
                    </form>
                </div>

                {/* Floating Alert Notification */}
                {alert && (
                    <div 
                        className={`fixed bottom-6 right-6 px-5 py-3.5 rounded-xl shadow-xl flex items-center gap-3 text-sm font-semibold transition-all border animate-bounce ${
                            alert.type === 'success' 
                                ? 'bg-emerald-900 text-emerald-50 border-emerald-800' 
                                : 'bg-rose-900 text-rose-50 border-rose-800'
                        }`} 
                        role="status"
                    >
                        <span className={`w-2 h-2 rounded-full ${alert.type === 'success' ? 'bg-emerald-400' : 'bg-rose-400'}`} />
                        {alert.message}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}