<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [TransactionController::class, 'index'])->name('dashboard');
    Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/analytics', [TransactionController::class, 'analytics'])->name('analytics');
    // Roles & Users management
    Route::get('/roles', [App\Http\Controllers\RoleController::class, 'index'])->name('roles.index')->middleware('permission:roles.view');
    Route::get('/roles/create', [App\Http\Controllers\RoleController::class, 'create'])->name('roles.create')->middleware('permission:roles.manage');
    Route::post('/roles', [App\Http\Controllers\RoleController::class, 'store'])->name('roles.store')->middleware('permission:roles.manage');
    Route::get('/roles/{role}/edit', [App\Http\Controllers\RoleController::class, 'edit'])->name('roles.edit')->middleware('permission:roles.manage');
    Route::put('/roles/{role}', [App\Http\Controllers\RoleController::class, 'update'])->name('roles.update')->middleware('permission:roles.manage');
    Route::delete('/roles/{role}', [App\Http\Controllers\RoleController::class, 'destroy'])->name('roles.destroy')->middleware('permission:roles.manage');

    Route::get('/users', [App\Http\Controllers\UserController::class, 'index'])->name('users.index')->middleware('permission:users.view');
    Route::get('/users/{user}/edit', [App\Http\Controllers\UserController::class, 'edit'])->name('users.edit')->middleware('permission:users.edit');
    Route::post('/users/{user}/roles', [App\Http\Controllers\UserController::class, 'updateRoles'])->name('users.roles.update')->middleware('permission:users.edit');
    // Settings area: redirect to currency manager
    Route::redirect('/settings', '/settings/currency')->name('settings');

    // Currency manager (existing SettingsController)
    Route::get('/settings/currency', [App\Http\Controllers\SettingsController::class, 'index'])->name('settings.currency');
    Route::post('/settings/currency', [App\Http\Controllers\SettingsController::class, 'store'])->name('settings.currency.store');

    // Settings - Roles management under /settings/roles
    Route::get('/settings/roles/permissions', function () {
        $perms = Spatie\Permission\Models\Permission::all()->groupBy('module');
        return response()->json($perms);
    })->middleware('permission:roles.view');

    Route::get('/settings/roles', function () {
        $roles = Spatie\Permission\Models\Role::withCount('users')->with('permissions')->get();
        return Inertia\Inertia::render('Settings/RoleManager', ['roles' => $roles]);
    })->name('settings.roles.index')->middleware('permission:roles.view');

    Route::get('/settings/roles/create', [App\Http\Controllers\RoleController::class, 'create'])->name('settings.roles.create')->middleware('permission:roles.manage');
    Route::post('/settings/roles', [App\Http\Controllers\RoleController::class, 'store'])->name('settings.roles.store')->middleware('permission:roles.manage');
    Route::get('/settings/roles/{role}/edit', [App\Http\Controllers\RoleController::class, 'edit'])->name('settings.roles.edit')->middleware('permission:roles.manage');
    Route::put('/settings/roles/{role}', [App\Http\Controllers\RoleController::class, 'update'])->name('settings.roles.update')->middleware('permission:roles.manage');
    Route::delete('/settings/roles/{role}', [App\Http\Controllers\RoleController::class, 'destroy'])->name('settings.roles.destroy')->middleware('permission:roles.manage');

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';