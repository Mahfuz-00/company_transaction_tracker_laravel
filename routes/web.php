<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VendorController;
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
    // Signed-in users go straight to their dashboard; guests see the landing page.
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
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

    // Legacy standalone entry point - redirects into the User Manager.
    Route::redirect('/users', '/settings/users')->name('users.index');

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

    // Settings - User management under /settings/users
    Route::get('/settings/users', [UserController::class, 'index'])
        ->name('settings.users.index')
        ->middleware('permission:users.view');

    Route::post('/settings/users', [UserController::class, 'store'])
        ->name('settings.users.store')
        ->middleware('permission:users.create');

    Route::put('/settings/users/{user}', [UserController::class, 'update'])
        ->name('settings.users.update')
        ->middleware('permission:users.edit');

    Route::patch('/settings/users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->name('settings.users.deactivate')
        ->middleware('permission:users.edit');

    Route::patch('/settings/users/{user}/activate', [UserController::class, 'activate'])
        ->name('settings.users.activate')
        ->middleware('permission:users.edit');

    Route::delete('/settings/users/{user}', [UserController::class, 'destroy'])
        ->name('settings.users.destroy')
        ->middleware('permission:users.delete');

    // Settings - Institution configuration (type, terminology, identity)
    Route::get('/settings/institution', [InstitutionController::class, 'edit'])
        ->name('settings.institution.edit')
        ->middleware('permission:institution.view');

    Route::put('/settings/institution', [InstitutionController::class, 'update'])
        ->name('settings.institution.update')
        ->middleware('permission:institution.manage');

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Meal Management routes
    Route::prefix('meals')->name('meals.')->group(function () {
        // Departments: read for anyone who can view, write only for managers.
        Route::resource('departments', DepartmentController::class)
            ->except(['show'])
            ->middleware([
                'index' => 'permission:departments.view',
                'create' => 'permission:departments.manage',
                'store' => 'permission:departments.manage',
                'edit' => 'permission:departments.manage',
                'update' => 'permission:departments.manage',
                'destroy' => 'permission:departments.manage',
            ]);

        // Students: same split.
        Route::resource('students', StudentController::class)->middleware([
            'index' => 'permission:students.view',
            'show' => 'permission:students.view',
            'create' => 'permission:students.manage',
            'store' => 'permission:students.manage',
            'edit' => 'permission:students.manage',
            'update' => 'permission:students.manage',
            'destroy' => 'permission:students.manage',
        ]);

        Route::resource('deposits', DepositController::class)
            ->only(['index', 'create', 'store'])
            ->middleware('permission:meals.deposit');
        Route::resource('entries', MealEntryController::class)->only(['index', 'create', 'store'])->middleware('permission:meals.entry');
        Route::resource('expenses', MealExpenseController::class)->only(['index', 'create', 'store'])->middleware('permission:meals.expense');
        Route::get('reports', [MealReportController::class, 'index'])->name('reports.index')->middleware('permission:meals.reports');

        // Vendors / suppliers.
        Route::resource('vendors', VendorController::class)
            ->except(['create', 'edit', 'show'])
            ->middleware([
                'index' => 'permission:vendors.view',
                'store' => 'permission:vendors.manage',
                'update' => 'permission:vendors.manage',
                'destroy' => 'permission:vendors.manage',
            ]);
    });
});

require __DIR__.'/auth.php';