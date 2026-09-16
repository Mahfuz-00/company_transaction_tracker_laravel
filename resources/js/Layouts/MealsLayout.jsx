import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link } from '@inertiajs/react';
import useCan from '@/Utils/can';

/**
 * Shared shell for every Meal Management screen: page header + module tabs.
 * Tabs are permission-filtered, so a viewer only sees what they can open.
 */
const TABS = [
    { label: 'Students', route: 'meals.students.index', match: 'meals.students.*', permission: 'students.view' },
    { label: 'Departments', route: 'meals.departments.index', match: 'meals.departments.*', permission: 'departments.view' },
    { label: 'Deposits', route: 'meals.deposits.index', match: 'meals.deposits.*', permission: 'meals.deposit' },
    { label: 'Meal Entries', route: 'meals.entries.index', match: 'meals.entries.*', permission: 'meals.entry' },
    { label: 'Expenses', route: 'meals.expenses.index', match: 'meals.expenses.*', permission: 'meals.expense' },
    { label: 'Reports', route: 'meals.reports.index', match: 'meals.reports.*', permission: 'meals.reports' },
];

export default function MealsLayout({ title, description, actions, children }) {
    const { can } = useCan();

    const visibleTabs = TABS.filter((tab) => can(tab.permission));

    return (
        <AuthenticatedLayout
            header={
                <h2 className="font-semibold text-xl text-slate-800 leading-tight">
                    {title || 'Meal Management'}
                </h2>
            }
        >
            <div className="space-y-5">
                {/* Module tabs */}
                {visibleTabs.length > 1 && (
                    <nav
                        aria-label="Meal management sections"
                        className="flex flex-wrap gap-1.5 border-b border-slate-200"
                    >
                        {visibleTabs.map((tab) => {
                            const active = route().current(tab.match);

                            return (
                                <Link
                                    key={tab.route}
                                    href={route(tab.route)}
                                    aria-current={active ? 'page' : undefined}
                                    className={`-mb-px border-b-2 px-3.5 py-2.5 text-sm font-semibold transition-colors ${active
                                            ? 'border-indigo-600 text-indigo-600'
                                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
                                        }`}
                                >
                                    {tab.label}
                                </Link>
                            );
                        })}
                    </nav>
                )}

                {/* Page header */}
                {(title || actions) && (
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-lg font-bold text-slate-900">{title}</h3>
                            {description && (
                                <p className="mt-0.5 text-sm text-slate-500">{description}</p>
                            )}
                        </div>
                        {actions && <div className="flex items-center gap-2">{actions}</div>}
                    </div>
                )}

                {children}
            </div>
        </AuthenticatedLayout>
    );
}
