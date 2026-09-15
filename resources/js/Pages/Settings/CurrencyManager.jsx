import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { useCurrencySettings } from '@/Utils/useCurrency';
import formatNumber from '@/Utils/numberFormatter';
import Button from '@/Components/UI/Button';
import Input from '@/Components/UI/Input';
import Card from '@/Components/UI/Card';

export default function Settings({ auth, currencies, userSettings }) {
    const { data, setData, post, processing } = useForm({
        currency_code: userSettings?.currency_code || '',
        symbol_position: userSettings?.symbol_position || 'before',
        decimal_separator: userSettings?.decimal_separator || '.',
        thousands_separator: userSettings?.thousands_separator || ',',
        decimal_precision: userSettings?.decimal_precision ?? 2,
        numbering_system: userSettings?.numbering_system || 'short',
        abbreviations: userSettings?.abbreviations ?? true,
    });

    const [settings, saveLocal] = useCurrencySettings();
    const [previewValue, setPreviewValue] = useState(1234567.89);

    useEffect(() => {
        try {
            const symbol = currencies?.find((c) => c.code === data.currency_code)?.symbol || data.symbol || data.sign || '$';
            const position = data.symbol_position || data.position || 'before';
            const normalized = {
                sign: symbol,
                position,
                decimal_separator: data.decimal_separator,
                thousands_separator: data.thousands_separator,
                decimal_precision: data.decimal_precision,
                numbering_system: data.numbering_system,
                abbreviations: data.abbreviations,
                currency_code: data.currency_code,
            };
            localStorage.setItem('currency_settings', JSON.stringify(normalized));
        } catch (e) {}
    }, [data, currencies]);

    const handleSave = (e) => {
        e.preventDefault();
        post(route('settings.store'), {
            data,
            onSuccess: () => {
                try {
                    const symbol = currencies?.find((c) => c.code === data.currency_code)?.symbol || data.symbol || data.sign || '$';
                    const position = data.symbol_position || data.position || 'before';
                    const normalized = {
                        sign: symbol,
                        position,
                        decimal_separator: data.decimal_separator,
                        thousands_separator: data.thousands_separator,
                        decimal_precision: data.decimal_precision,
                        numbering_system: data.numbering_system,
                        abbreviations: data.abbreviations,
                        currency_code: data.currency_code,
                    };
                    localStorage.setItem('currency_settings', JSON.stringify(normalized));
                    try { saveLocal(normalized); } catch (e) {}
                } catch (e) {}
            }
        });
    };

    const separatorsConflict = data.decimal_separator === data.thousands_separator;

    return (
        <SettingsLayout 
            user={auth?.user} 
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">System Settings</h2>}
        >
            <Head title="Settings" />

            <div className="py-8 px-6 max-w-7xl mx-auto space-y-6">
                <form onSubmit={handleSave} className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    {/* Settings Input Form */}
                    <Card className="lg:col-span-7 p-6 border border-gray-100 shadow-sm rounded-xl bg-white space-y-6">
                        <div>
                            <h3 className="text-lg font-bold text-gray-900">Currency & Formatting</h3>
                            <p className="text-xs text-gray-500 mt-0.5">Customize how numerical figures and transaction values appear across the app.</p>
                        </div>

                        {/* Section 1: Currency & Symbol */}
                        <div className="space-y-4 pt-2">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">1. Currency Details</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label htmlFor="currency_code" className="block text-xs font-semibold text-gray-600 mb-1">
                                        Active Currency
                                    </label>
                                    <select 
                                        id="currency_code" 
                                        value={data.currency_code} 
                                        onChange={(e) => setData('currency_code', e.target.value)} 
                                        className="w-full border-gray-300 rounded-lg shadow-sm text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                                    >
                                        <option value="">-- Select currency --</option>
                                        {currencies.map((c) => (
                                            <option key={c.id} value={c.code}>{`${c.name} (${c.code}) — ${c.symbol || ''}`}</option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="symbol_position" className="block text-xs font-semibold text-gray-600 mb-1">
                                        Symbol Placement
                                    </label>
                                    <select 
                                        id="symbol_position" 
                                        value={data.symbol_position} 
                                        onChange={(e) => setData('symbol_position', e.target.value)} 
                                        className="w-full border-gray-300 rounded-lg shadow-sm text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                                    >
                                        <option value="before">Before ($100)</option>
                                        <option value="before_space">Before with space ($ 100)</option>
                                        <option value="after">After (100$)</option>
                                        <option value="after_space">After with space (100 $)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr className="border-gray-100" />

                        {/* Section 2: Separators & Precision */}
                        <div className="space-y-4">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">2. Separators & Precision</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label htmlFor="decimal_separator" className="block text-xs font-semibold text-gray-600 mb-1">
                                        Decimal Separator
                                    </label>
                                    <select 
                                        id="decimal_separator" 
                                        value={data.decimal_separator} 
                                        onChange={(e) => setData('decimal_separator', e.target.value)} 
                                        className="w-full border-gray-300 rounded-lg shadow-sm text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                                    >
                                        <option value=".">Period (.)</option>
                                        <option value=",">Comma (,)</option>
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="thousands_separator" className="block text-xs font-semibold text-gray-600 mb-1">
                                        Thousands Separator
                                    </label>
                                    <select 
                                        id="thousands_separator" 
                                        value={data.thousands_separator} 
                                        onChange={(e) => setData('thousands_separator', e.target.value)} 
                                        className="w-full border-gray-300 rounded-lg shadow-sm text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                                    >
                                        <option value=",">Comma (,)</option>
                                        <option value=".">Period (.)</option>
                                        <option value=" ">Space ( )</option>
                                        <option value="">None</option>
                                    </select>
                                </div>

                                <div>
                                    <Input 
                                        id="decimal_precision" 
                                        label="Decimal Precision" 
                                        type="number" 
                                        min={0} 
                                        value={data.decimal_precision} 
                                        onChange={(e) => setData('decimal_precision', Number(e.target.value))} 
                                    />
                                </div>
                            </div>

                            {separatorsConflict && (
                                <div id="separator-error" role="alert" aria-invalid="true" className="p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2 text-red-700 text-xs font-medium mt-2">
                                    <svg className="w-4 h-4 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Decimal and thousands separators cannot be identical.
                                </div>
                            )}
                        </div>

                        <hr className="border-gray-100" />

                        {/* Section 3: Regional Scales */}
                        <div className="space-y-4">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">3. Scale & Display Options</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                                <div>
                                    <label htmlFor="numbering_system" className="block text-xs font-semibold text-gray-600 mb-1">
                                        Numbering Scale
                                    </label>
                                    <select 
                                        id="numbering_system" 
                                        value={data.numbering_system} 
                                        onChange={(e) => setData('numbering_system', e.target.value)} 
                                        className="w-full border-gray-300 rounded-lg shadow-sm text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                                    >
                                        <option value="short">Short scale (Million, Billion)</option>
                                        <option value="long">Long scale</option>
                                        <option value="indian">Indian (Lakh, Crore)</option>
                                        <option value="east_asian">East Asian (Wan, Yi)</option>
                                    </select>
                                </div>

                                <div className="flex items-center gap-2.5 pt-5">
                                    <input 
                                        id="abbreviations" 
                                        type="checkbox" 
                                        checked={data.abbreviations} 
                                        onChange={(e) => setData('abbreviations', e.target.checked)} 
                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 transition-colors" 
                                    />
                                    <label htmlFor="abbreviations" className="text-sm font-medium text-gray-700 select-none">
                                        Enable compact scale labels (e.g. 1.5 Mil)
                                    </label>
                                </div>
                            </div>
                        </div>

                        {/* Form Submit */}
                        <div className="pt-4 flex justify-end">
                            <Button type="submit" disabled={processing || separatorsConflict} className="px-6 py-2.5 text-sm font-semibold shadow-sm">
                                {processing ? 'Saving...' : 'Save Settings'}
                            </Button>
                        </div>
                    </Card>

                    {/* Sticky Live Preview */}
                    <Card className="lg:col-span-5 p-6 border border-gray-100 shadow-sm rounded-xl bg-gray-50/50 sticky top-6 space-y-6">
                        <div className="flex items-center justify-between border-b border-gray-200/60 pb-3">
                            <div>
                                <h3 className="text-base font-bold text-gray-900">Live Formatting Preview</h3>
                                <p className="text-xs text-gray-500">Test how numbers appear in real-time</p>
                            </div>
                            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700">
                                Real-time
                            </span>
                        </div>

                        <div>
                            <Input 
                                id="preview_input" 
                                label="Sample Number Input" 
                                type="number" 
                                value={previewValue} 
                                onChange={(e) => setPreviewValue(Number(e.target.value))} 
                            />
                        </div>

                        <div className="space-y-4">
                            <div className="p-4 bg-white rounded-lg border border-gray-200/80 shadow-xs">
                                <div className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Standard Format</div>
                                <div className="mt-1 text-2xl font-bold text-gray-900 tracking-tight" role="status" aria-live="polite">
                                    {formatNumber(previewValue, data, currencies)}
                                </div>
                            </div>

                            <div className="p-4 bg-white rounded-lg border border-gray-200/80 shadow-xs">
                                <div className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Abbreviated Output</div>
                                <div className="mt-1 text-2xl font-bold text-indigo-600 tracking-tight" role="status" aria-live="polite">
                                    {data.abbreviations ? formatNumber(previewValue, { ...data, abbreviated: true }, currencies) : <span className="text-gray-400 font-normal text-base">— Disabled —</span>}
                                </div>
                            </div>
                        </div>
                    </Card>

                </form>
            </div>
        </SettingsLayout>
    );
}