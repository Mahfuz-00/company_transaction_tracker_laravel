<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClaimApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DepositApiController;
use App\Http\Controllers\Api\MealApiController;
use App\Http\Controllers\Api\MemberApiController;
use App\Http\Controllers\Api\MemberPaymentApiController;
use App\Http\Controllers\Api\MemberSelfApiController;
use App\Http\Controllers\Api\MenuApiController;
use App\Http\Controllers\Api\ReferenceApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\SelfApiController;
use App\Http\Controllers\Api\SubsidyApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API
|--------------------------------------------------------------------------
|
| Token-authenticated JSON API backing the mobile application. Authentication
| uses Laravel Sanctum personal access tokens: the client exchanges email +
| password (or a device name) for a bearer token, then sends it on every call.
|
| Conventions, applied consistently across every endpoint:
|
|   * Responses are wrapped in { data, meta } where a collection is returned.
|   * Money is returned as a number (float); currency metadata is served once
|     by /meta so the client formats locally.
|   * Errors use the standard HTTP codes with a { message, errors } body.
|   * Dates are ISO-8601 (YYYY-MM-DD) except month filters, which use YYYY-MM.
|
| See docs/API.md for the full reference with example payloads.
|
*/

// ---- Public ---------------------------------------------------------------
// The auth endpoints carry an EXTRA, stricter throttle on top of the baseline
// 'api' group limiter: 5 requests/min keyed per email + IP, so credential
// guessing is slowed without one attacker locking out other accounts.
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:api-login');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login');

// Server + contract metadata. Useful for the mobile client to discover
// capabilities and confirm the currency/terminology it should render.
Route::get('/meta', [AuthController::class, 'meta']);

// ---- Authenticated --------------------------------------------------------
//
// `mobile.not-ssa` is the OUTERMOST guard on this whole group: a Software Super
// Admin token can never reach ANY protected endpoint, because their session has
// no tenant scope and would expose every institution's data. See
// App\Http\Middleware\EnsureNotSoftwareSuperAdmin.
Route::middleware(['auth:sanctum', 'mobile.not-ssa'])->group(function () {
    // Session / identity
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/devices', [AuthController::class, 'registerDevice']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    // Change your own password. Revokes every OTHER device token.
    Route::put('/auth/password', [SelfApiController::class, 'updatePassword']);

    // Dashboard summary (staff: the whole institution)
    Route::get('/dashboard', [DashboardApiController::class, 'index']);

    /*
     * MY ACCOUNT - the MEMBER'S OWN data. Every route resolves the member from
     * the token, so no member id is ever accepted and cross-member reads are
     * structurally impossible. Open to any authenticated non-SSA user.
     */
    Route::get('/me/dashboard', [MemberSelfApiController::class, 'dashboard']);
    Route::get('/me/meals', [MemberSelfApiController::class, 'meals']);
    Route::get('/me/deposits', [MemberSelfApiController::class, 'deposits']);
    Route::get('/me/analytics', [MemberSelfApiController::class, 'analytics']);
    Route::put('/me/theme', [SelfApiController::class, 'updateTheme']);

    // Notifications - the caller's own only. Any authenticated user.
    Route::get('/notifications', [SelfApiController::class, 'notifications']);
    Route::get('/notifications/latest', [SelfApiController::class, 'latestNotifications']);
    Route::post('/notifications/read-all', [SelfApiController::class, 'markAllNotificationsRead']);
    Route::post('/notifications/{notification}/read', [SelfApiController::class, 'markNotificationRead']);
    Route::delete('/notifications/{notification}', [SelfApiController::class, 'deleteNotification']);
    Route::post('/notifications/announce', [SelfApiController::class, 'announce'])
        ->middleware('permission:notifications.announce');

    /*
     * MEMBER PAYMENTS - a member SUBMITS a payment here; only staff can approve
     * it (the `meals.deposit` gate on the verification routes). A member holding
     * `meals.deposit` is impossible by role design.
     */
    Route::get('/me/payments', [MemberPaymentApiController::class, 'index']);
    Route::post('/me/payments', [MemberPaymentApiController::class, 'store']);
    Route::get('/member-payments', [MemberPaymentApiController::class, 'queue'])
        ->middleware('permission:meals.deposit');
    Route::patch('/member-payments/{memberPayment}/approve', [MemberPaymentApiController::class, 'approve'])
        ->middleware('permission:meals.deposit');
    Route::patch('/member-payments/{memberPayment}/reject', [MemberPaymentApiController::class, 'reject'])
        ->middleware('permission:meals.deposit');

    /*
     * CLAIMS - a member sees and raises only their own; staff review. The review
     * routes carry `claims.review`, which a Member does not hold.
     */
    Route::get('/claims', [ClaimApiController::class, 'index']);
    Route::post('/claims', [ClaimApiController::class, 'store']);
    Route::get('/claims/review', [ClaimApiController::class, 'review'])
        ->middleware('permission:claims.review');
    Route::patch('/claims/{claim}/approve', [ClaimApiController::class, 'approve'])
        ->middleware('permission:claims.review');
    Route::patch('/claims/{claim}/reject', [ClaimApiController::class, 'reject'])
        ->middleware('permission:claims.review');

    /*
     * MEAL MENUS & VOTING - everyone may READ what is open for voting; only
     * staff (`meals.reports`) may create a menu or move it through its lifecycle.
     */
    Route::get('/menus', [MenuApiController::class, 'index']);
    Route::get('/menus/{menu}', [MenuApiController::class, 'show']);
    Route::post('/menus/{menu}/vote', [MenuApiController::class, 'vote']);
    Route::post('/menus', [MenuApiController::class, 'store'])->middleware('permission:meals.reports');
    Route::patch('/menus/{menu}/status', [MenuApiController::class, 'updateStatus'])
        ->middleware('permission:meals.reports');

    // Members (the roster - "students" internally). Staff only.
    Route::get('/members', [MemberApiController::class, 'index'])->middleware('permission:students.view');
    Route::get('/members/{member}', [MemberApiController::class, 'show'])->middleware('permission:students.view');
    Route::post('/members', [MemberApiController::class, 'store'])->middleware('permission:students.manage');
    Route::patch('/members/{member}', [MemberApiController::class, 'update'])->middleware('permission:students.manage');
    Route::delete('/members/{member}', [MemberApiController::class, 'destroy'])->middleware('permission:students.manage');

    // Deposits (Cash In) and Expenses (Cash Out)
    Route::get('/deposits', [DepositApiController::class, 'index'])->middleware('permission:meals.deposit');
    Route::post('/deposits', [DepositApiController::class, 'store'])->middleware('permission:meals.deposit');
    Route::get('/deposits/export', [DepositApiController::class, 'export'])->middleware('permission:meals.deposit');

    Route::get('/expenses', [ReferenceApiController::class, 'expenses'])->middleware('permission:meals.expense');
    Route::post('/expenses', [ReferenceApiController::class, 'storeExpense'])->middleware('permission:meals.expense');

    // Meal entries
    Route::get('/meals', [MealApiController::class, 'index']);
    Route::get('/meals/day', [MealApiController::class, 'day'])->middleware('permission:meals.view');
    Route::post('/meals/day', [MealApiController::class, 'storeDay'])->middleware('permission:meals.entry');

    // Subsidies
    Route::get('/subsidies', [SubsidyApiController::class, 'index'])->middleware('permission:subsidies.view');
    Route::post('/subsidies', [SubsidyApiController::class, 'store'])->middleware('permission:subsidies.manage');
    Route::get('/subsidies/sources', [SubsidyApiController::class, 'sources'])->middleware('permission:subsidies.view');

    // Vendors + departments (configuration the manager reads in the field)
    Route::get('/vendors', [ReferenceApiController::class, 'vendors'])->middleware('permission:vendors.view');
    Route::post('/vendors', [ReferenceApiController::class, 'storeVendor'])->middleware('permission:vendors.manage');
    Route::get('/departments', [ReferenceApiController::class, 'departments'])->middleware('permission:departments.view');
    Route::post('/departments', [ReferenceApiController::class, 'storeDepartment'])->middleware('permission:departments.manage');

    // Workspace settings (identity, invite code, currency, subsidy sources)
    Route::get('/settings/institution', [ReferenceApiController::class, 'institutionSettings'])
        ->middleware('permission:institution.view');
    Route::put('/settings/institution', [ReferenceApiController::class, 'updateInstitution'])
        ->middleware('permission:institution.manage');
    Route::get('/settings/subsidy-sources', [ReferenceApiController::class, 'subsidySources'])
        ->middleware('permission:subsidies.view');

    // Reports + analytics (includes the forecast). Staff only.
    Route::get('/reports/meal', [ReportApiController::class, 'meal'])->middleware('permission:meals.reports');
    Route::get('/reports/analytics', [ReportApiController::class, 'analytics'])->middleware('permission:transactions.view');
    Route::get('/reports/forecast', [ReportApiController::class, 'forecast'])->middleware('permission:meals.reports');
    Route::get('/reports/per-meal-rate', [ReportApiController::class, 'perMealRate'])->middleware('permission:meals.reports');
});
