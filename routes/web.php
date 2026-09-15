<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Meals\DepartmentController;
use App\Http\Controllers\Meals\StudentController;
use App\Http\Controllers\Meals\DepositController;
use App\Http\Controllers\Meals\MealEntryController;
use App\Http\Controllers\Meals\MealExpenseController;
use App\Http\Controllers\Meals\MealReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [TransactionController::class, 'index'])->name('dashboard');
    Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/analytics', [TransactionController::class, 'analytics'])->name('analytics');

    // Roles & Users management (standalone)
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index')->middleware('permission:roles.view');
    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create')->middleware('permission:roles.manage');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store')->middleware('permission:roles.manage');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->middleware('permission:roles.manage');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->middleware('permission:roles.manage');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->middleware('permission:roles.manage');

    Route::get('/users', [UserController::class, 'index'])->name('users.index')->middleware('permission:users.view');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->middleware('permission:users.edit');
    Route::post('/users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles.update')->middleware('permission:users.edit');

    // Settings area: redirect to currency manager
    Route::redirect('/settings', '/settings/currency')->name('settings');

    // Currency manager
    Route::get('/settings/currency', [SettingsController::class, 'index'])->name('settings.currency');
    Route::post('/settings/currency', [SettingsController::class, 'store'])->name('settings.currency.store');

    // Settings - Roles management under /settings/roles
    Route::get('/settings/roles/permissions', function () {
        $perms = Permission::all()->groupBy('module');
        return response()->json($perms);
    })->middleware('permission:roles.view');

    Route::get('/settings/roles', function () {
        $roles = Role::withCount('users')->with('permissions')->get();
        return Inertia::render('Settings/RoleManager', [
            'roles' => $roles,
        ]);
    })->name('settings.roles.index')->middleware('permission:roles.view');

    Route::get('/settings/roles/create', [RoleController::class, 'create'])
        ->name('settings.roles.create')
        ->middleware('permission:roles.manage');

    Route::post('/settings/roles', [RoleController::class, 'store'])
        ->name('settings.roles.store')
        ->middleware('permission:roles.manage');

    Route::get('/settings/roles/{role}/edit', [RoleController::class, 'edit'])
        ->name('settings.roles.edit')
        ->middleware('permission:roles.manage');

    Route::put('/settings/roles/{role}', [RoleController::class, 'update'])
        ->name('settings.roles.update')
        ->middleware('permission:roles.manage');

    Route::delete('/settings/roles/{role}', [RoleController::class, 'destroy'])
        ->name('settings.roles.destroy')
        ->middleware('permission:roles.manage');

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Meal Management routes
    Route::prefix('meals')->name('meals.')->group(function () {
        Route::resource('departments', DepartmentController::class)->except(['show'])->middleware('permission:departments.manage');
        Route::resource('students', StudentController::class)->middleware('permission:students.view');
        Route::resource('deposits', DepositController::class)->only(['index', 'create', 'store'])->middleware('permission:meals.deposit');
        Route::resource('entries', MealEntryController::class)->only(['index', 'create', 'store'])->middleware('permission:meals.entry');
        Route::resource('expenses', MealExpenseController::class)->only(['index', 'create', 'store'])->middleware('permission:meals.expense');
        Route::get('reports', [MealReportController::class, 'index'])->name('reports.index')->middleware('permission:meals.reports');
    });
});

require __DIR__.'/auth.php';