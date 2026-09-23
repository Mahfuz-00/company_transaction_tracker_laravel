<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MemberDashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\InstitutionBroadcastController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\InstitutionRegistryController;
use App\Http\Controllers\InviteCodeController;
use App\Http\Controllers\GlobalAuditController;
use App\Http\Controllers\LandingEnquiryController;
use App\Http\Controllers\MemberInvitationController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PlatformBroadcastController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SaaSAnalyticsController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\SubsidySourceController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TrialManagementController;
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
use App\Http\Controllers\Meals\SubsidyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

Route::get('/', function () {
    // Signed-in users go straight to their dashboard; guests see the landing page.
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return app(\App\Http\Controllers\LandingController::class)->index();
})->name('home');

// Public lead capture from the landing page's "Request a demo" form.
Route::post('/contact', [\App\Http\Controllers\LandingController::class, 'contact'])
    ->middleware('throttle:10,1')
    ->name('landing.contact');

/**
 * Password setup from a signed link (invitation OR reset).
 *
 * The GET is protected by Laravel's `signed` middleware, so a tampered URL is
 * rejected before we ever look the invitation up. The POST re-validates the
 * opaque token inside the controller (hash_equals + expiry + single-use) as
 * defence in depth. Both live outside the auth group - the recipient is a guest.
 */
Route::get('/password/setup/{invitation}', [PasswordSetupController::class, 'show'])
    ->name('password.setup')
    ->middleware('signed');

Route::post('/password/setup/{invitation}', [PasswordSetupController::class, 'store'])
    ->name('password.setup.store');

/**
 * Legacy invitation aliases. Existing emailed links and bookmarks keep working -
 * they now render the same, upgraded password-setup screen.
 */
Route::get('/invitations/{invitation}/accept', [PasswordSetupController::class, 'show'])
    ->name('invitations.accept')
    ->middleware('signed');

Route::post('/invitations/{invitation}/complete', [PasswordSetupController::class, 'store'])
    ->name('invitations.complete');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * MEMBER AREA. Every route here is gated by the Member role and scoped to
     * the signed-in member's own data in the controller - a member can never
     * reach another member's records or the institution's pooled figures.
     */
    Route::middleware(['permission:meals.view', 'role:Member'])->prefix('my')->name('member.')->group(function () {
        // Merged personal summary: month figures, deposits, meal history, balance.
        Route::get('/dashboard', [MemberDashboardController::class, 'index'])->name('dashboard');
        // Own meal entries, day by day.
        Route::get('/meals', [MemberDashboardController::class, 'meals'])->name('meals');
        // Own deposit history.
        Route::get('/deposits', [MemberDashboardController::class, 'deposits'])->name('deposits');
        // Personal analytics, scoped exclusively to this member.
        Route::get('/analytics', [MemberDashboardController::class, 'analytics'])->name('analytics');
    });

    // Forced / voluntary password change. Reachable even while a user still
    // holds a temporary password (the middleware allow-lists these names).
    Route::get('/password/change', [ProfileController::class, 'showChangePassword'])->name('password.change');
    Route::put('/password/change', [ProfileController::class, 'updatePassword'])->name('password.change.update');

    // Email Log / Outbox. SSA sees the whole platform; IA / Meal Manager are
    // scoped to their institution (enforced in the controller).
    Route::get('/settings/emails', [EmailLogController::class, 'index'])
        ->name('settings.emails.index')
        ->middleware('permission:emails.view');
    Route::get('/settings/emails/{emailLog}', [EmailLogController::class, 'show'])
        ->name('settings.emails.show')
        ->middleware('permission:emails.view');

    // In-app notifications. Every role sees their own; admins may broadcast.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::post('/notifications/announce', [NotificationController::class, 'announce']) ->name('notifications.announce');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // WORKSPACE BROADCASTS (Institution Admin). STRICTLY institution-scoped:
    // the controller pins the target institution from the signed-in user (never
    // from request input) and delivers only to that institution's users. Gated
    // by `notifications.announce`, which the Institution Admin holds and the
    // Meal Manager / Member do not.
    Route::get('/settings/broadcasts', [InstitutionBroadcastController::class, 'index'])
        ->name('broadcasts.index')
        ->middleware('permission:notifications.announce');
    Route::post('/settings/broadcasts', [InstitutionBroadcastController::class, 'store'])
        ->name('broadcasts.store')
        ->middleware('permission:notifications.announce');

    // MEMBER-ONLY: a member's own claim submissions + status. Managers review
    // claims through the separate /claims/review queue below.
    Route::get('/claims', [ClaimController::class, 'index'])
        ->name('claims.index')
        ->middleware(['permission:claims.view', 'role:Member']);
    Route::post('/claims', [ClaimController::class, 'store'])
        ->name('claims.store')
        ->middleware('permission:claims.submit');

    // Manager review queue + decisions.
    Route::get('/claims/review', [ClaimController::class, 'review'])
        ->name('claims.review')
        ->middleware('permission:claims.review');
    Route::patch('/claims/{claim}/approve', [ClaimController::class, 'approve'])
        ->name('claims.approve')
        ->middleware('permission:claims.review');
    Route::patch('/claims/{claim}/reject', [ClaimController::class, 'reject'])
        ->name('claims.reject')
        ->middleware('permission:claims.review');

    // The standalone "Add Transaction" module was removed: Cash In is now a
    // Deposit and Cash Out is an Expense, each with its own module. The old
    // route still resolves so existing bookmarks keep working - it redirects
    // to the right module rather than showing a generic form.
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

    /*
     * CURRENCY MANAGER - a WORKSPACE setting.
     *
     * Reachable by anyone who can VIEW it (admins + managers), but the POST is
     * gated by `currency.manage`, which only Institution Admins (for their own
     * institution) and the SSA hold. The controller re-asserts ownership, so an
     * individual user can never alter the format even if the route leaked.
     */
    Route::get('/settings/currency', [SettingsController::class, 'index'])
        ->name('settings.currency')
        ->middleware('permission:currency.view');
    Route::post('/settings/currency', [SettingsController::class, 'store'])
        ->name('settings.currency.store')
        ->middleware('permission:currency.manage');

    /*
     * Theme Customizer - a dedicated settings sub-module available to EVERY
     * authenticated user (member included). It is deliberately NOT gated by a
     * permission: personalising one's own view is a personal preference, not an
     * administrative act. Each user only ever edits their OWN `users.theme`.
     */
    Route::get('/settings/theme', [ThemeController::class, 'edit'])->name('settings.theme.edit');
    Route::put('/settings/theme', [ThemeController::class, 'update'])->name('settings.theme.update');
    Route::post('/settings/theme/reset', [ThemeController::class, 'reset'])->name('settings.theme.reset');

    // Audit trail / activity log. Super Admins see everything; Institution
    // Admins see their own institution (scoped in the controller).
    Route::get('/settings/activity', [ActivityLogController::class, 'index'])
        ->name('settings.activity.index')
        ->middleware('permission:audit.view');

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

    /*
     * USER MANAGER - Admins and the SSA ONLY.
     *
     * Every route requires the `users.*` permission (which a Meal Manager does
     * NOT hold) AND an admin role. The controller ALSO re-asserts the role and
     * excludes global Super Admin accounts from an Institution Admin's scope.
     */
    Route::get('/settings/users', [UserController::class, 'index'])
        ->name('settings.users.index')
        ->middleware(['permission:users.view', 'role:Software Super Admin|Institution Admin']);

    Route::post('/settings/users', [UserController::class, 'store'])
        ->name('settings.users.store')
        ->middleware(['permission:users.create', 'role:Software Super Admin|Institution Admin']);

    Route::put('/settings/users/{user}', [UserController::class, 'update'])
        ->name('settings.users.update')
        ->middleware(['permission:users.edit', 'role:Software Super Admin|Institution Admin']);

    Route::patch('/settings/users/{user}/deactivate', [UserController::class, 'deactivate'])
        ->name('settings.users.deactivate')
        ->middleware(['permission:users.edit', 'role:Software Super Admin|Institution Admin']);

    Route::patch('/settings/users/{user}/activate', [UserController::class, 'activate'])
        ->name('settings.users.activate')
        ->middleware(['permission:users.edit', 'role:Software Super Admin|Institution Admin']);

    Route::delete('/settings/users/{user}', [UserController::class, 'destroy'])
        ->name('settings.users.destroy')
        ->middleware(['permission:users.delete', 'role:Software Super Admin|Institution Admin']);

    // Settings - Institution configuration (type, terminology, identity,
    // theme + branding).
    Route::get('/settings/institution', [InstitutionController::class, 'edit'])
        ->name('settings.institution.edit')
        ->middleware('permission:institution.view');

    Route::put('/settings/institution', [InstitutionController::class, 'update'])
        ->name('settings.institution.update')
        ->middleware('permission:institution.manage');

    /*
     * Settings - the workshop's INVITE CODE.
     *
     * The code is the tenant-mapping key a member types on the public signup
     * form (RegisteredUserController::store -> Institution::findByInviteCode).
     * It was generated and consumed in the backend but an Institution Admin had
     * no way to SEE or ROTATE it. This module closes that gap.
     */
    Route::get('/settings/invite-code', [InviteCodeController::class, 'show'])
        ->name('settings.invite-code.show')
        ->middleware('permission:institution.view');

    // Rotate the code (revokes old signup links). Managing the workspace belongs
    // to its Institution Admin (or an SSA switched into it) - institution.manage.
    Route::post('/settings/invite-code/regenerate', [InviteCodeController::class, 'regenerate'])
        ->name('settings.invite-code.regenerate')
        ->middleware('permission:institution.manage');

    // Settings - admin-managed subsidy funding sources with default shares.
    Route::get('/settings/subsidy-sources', [SubsidySourceController::class, 'index'])
        ->name('settings.subsidy-sources.index')
        ->middleware('permission:subsidies.view');
    Route::post('/settings/subsidy-sources', [SubsidySourceController::class, 'store'])
        ->name('settings.subsidy-sources.store')
        ->middleware('permission:subsidies.manage');
    Route::put('/settings/subsidy-sources/{subsidySource}', [SubsidySourceController::class, 'update'])
        ->name('settings.subsidy-sources.update')
        ->middleware('permission:subsidies.manage');
    Route::delete('/settings/subsidy-sources/{subsidySource}', [SubsidySourceController::class, 'destroy'])
        ->name('settings.subsidy-sources.destroy')
        ->middleware('permission:subsidies.manage');

    // Settings - the Software Super Admin's institution registry: every
    // institution on the platform with its administrators.
    Route::get('/settings/institutions', [InstitutionRegistryController::class, 'index'])
        ->name('settings.institutions.index')
        ->middleware('permission:institutions.view');
    Route::post('/settings/institutions', [InstitutionRegistryController::class, 'store'])
        ->name('settings.institutions.store')
        ->middleware('permission:institutions.manage');
    Route::patch('/settings/institutions/{institution}/toggle', [InstitutionRegistryController::class, 'toggle'])
        ->name('settings.institutions.toggle')
        ->middleware('permission:institutions.manage');
    // Switch the SSA into a specific institution's workspace (session-scoped).
    Route::patch('/settings/institutions/{institution}/switch', [InstitutionRegistryController::class, 'switchTo'])
        ->name('settings.institutions.switch')
        ->middleware('permission:institutions.manage');
    // Return the SSA to the global platform view (clears the session tenant).
    Route::post('/settings/institutions/exit', [InstitutionRegistryController::class, 'exitTenant'])
        ->name('settings.institutions.exit');

    /*
     * SSA BUSINESS MONITORING - the platform control tower.
     *
     * Cross-tenant by design (its queries run inside a sanctioned global scope),
     * so it is gated to the global role only. The controller re-asserts
     * isSuperAdmin() on every action as defence in depth.
     */
    Route::get('/settings/monitoring', [MonitoringController::class, 'index'])
        ->name('settings.monitoring.index')
        ->middleware('permission:monitoring.view');
    Route::put('/settings/monitoring/{institution}/subscription', [MonitoringController::class, 'updateSubscription'])
        ->name('settings.monitoring.subscription')
        ->middleware('permission:monitoring.manage');
    Route::get('/settings/monitoring/audit/export', [MonitoringController::class, 'exportAudit'])
        ->name('settings.monitoring.audit.export')
        ->middleware('permission:monitoring.view');

    /*
     * THE SSA'S DEFAULT LANDING PAGE - the Global SaaS Business Dashboard.
     *
     * `dashboard` redirects a Software Super Admin here when they have NOT
     * switched into a tenant, so an SSA never lands inside an individual
     * institution. It reuses the monitoring screen (same cross-tenant data) but
     * gets its own named route so the redirect target is explicit and greppable.
     */
    Route::get('/platform', [MonitoringController::class, 'index'])
        ->name('ssa.dashboard')
        ->middleware('permission:monitoring.view');

    /*
     * GLOBAL SAAS BUSINESS ANALYTICS - the platform-wide financial engine.
     * Replaces the tenant-scoped analytics view for the SSA (subscription
     * revenue, conversion, retention), NOT individual meal counts.
     */
    Route::get('/platform/analytics', [SaaSAnalyticsController::class, 'index'])
        ->name('ssa.analytics')
        ->middleware('permission:monitoring.view');

    /*
     * GLOBAL SYSTEM AUDIT & SECURITY LOG - cross-tenant activity, filterable by
     * institution and severity, with an exportable slice for compliance.
     */
    Route::get('/platform/audit', [GlobalAuditController::class, 'index'])
        ->name('ssa.audit.index')
        ->middleware('permission:monitoring.view');
    Route::get('/platform/audit/export', [GlobalAuditController::class, 'export'])
        ->name('ssa.audit.export')
        ->middleware('permission:monitoring.view');

    /*
     * PLATFORM ANNOUNCEMENTS & SYSTEM BROADCASTS - the SSA composing one message
     * for the whole platform (all staff / all members / everyone).
     */
    /*
     * LANDING ENQUIRIES / DEMO REQUESTS - SSA only.
     *
     * Turn a public demo request into a live institution: approve to provision
     * on a trial (or a plan), or mark contacted / rejected.
     */
    Route::get('/platform/enquiries', [LandingEnquiryController::class, 'index'])
        ->name('ssa.enquiries.index')
        ->middleware('permission:monitoring.view');
    Route::post('/platform/enquiries/{enquiry}/approve', [LandingEnquiryController::class, 'approve'])
        ->name('ssa.enquiries.approve')
        ->middleware('permission:monitoring.manage');
    Route::post('/platform/enquiries/{enquiry}/contact', [LandingEnquiryController::class, 'markContacted'])
        ->name('ssa.enquiries.contact')
        ->middleware('permission:monitoring.manage');
    Route::post('/platform/enquiries/{enquiry}/reject', [LandingEnquiryController::class, 'reject'])
        ->name('ssa.enquiries.reject')
        ->middleware('permission:monitoring.manage');

    Route::get('/platform/broadcasts', [PlatformBroadcastController::class, 'index'])
        ->name('ssa.broadcasts.index')
        ->middleware('permission:monitoring.view');
    Route::post('/platform/broadcasts', [PlatformBroadcastController::class, 'store'])
        ->name('ssa.broadcasts.store')
        ->middleware('permission:monitoring.manage');

    /*
     * PRICING & SUBSCRIPTION PLAN MANAGER - SSA only.
     *
     * Define the SaaS tiers and assign them to institutions. Gated by
     * `plans.*` (held by the global role alone) and re-asserted in the controller.
     */
    Route::get('/platform/plans', [SubscriptionPlanController::class, 'index'])
        ->name('ssa.plans.index')
        ->middleware('permission:plans.view');
    Route::post('/platform/plans', [SubscriptionPlanController::class, 'store'])
        ->name('ssa.plans.store')
        ->middleware('permission:plans.manage');
    Route::put('/platform/plans/{plan}', [SubscriptionPlanController::class, 'update'])
        ->name('ssa.plans.update')
        ->middleware('permission:plans.manage');
    Route::delete('/platform/plans/{plan}', [SubscriptionPlanController::class, 'destroy'])
        ->name('ssa.plans.destroy')
        ->middleware('permission:plans.manage');
    Route::post('/platform/plans/assign/{institution}', [SubscriptionPlanController::class, 'assign'])
        ->name('ssa.plans.assign')
        ->middleware('permission:plans.manage');

    /*
     * TRIAL & SUBSCRIPTION MANAGEMENT - the SSA's view of who is on a 7-day
     * trial vs a permanent subscription, with expiry countdowns and one-click
     * upgrade prompts.
     */
    Route::get('/settings/trials', [TrialManagementController::class, 'index'])
        ->name('settings.trials.index')
        ->middleware('permission:monitoring.view');
    Route::post('/settings/trials/{institution}/remind', [TrialManagementController::class, 'sendUpgradePrompt'])
        ->name('settings.trials.remind')
        ->middleware('permission:monitoring.manage');
    Route::post('/settings/trials/{institution}/convert', [TrialManagementController::class, 'convert'])
        ->name('settings.trials.convert')
        ->middleware('permission:monitoring.manage');
    Route::post('/settings/trials/{institution}/extend', [TrialManagementController::class, 'extendTrial'])
        ->name('settings.trials.extend')
        ->middleware('permission:monitoring.manage');

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

        // Students (members): same split.
        Route::resource('students', StudentController::class)->middleware([
            'index' => 'permission:students.view',
            'show' => 'permission:students.view',
            'create' => 'permission:students.manage',
            'store' => 'permission:students.manage',
            'edit' => 'permission:students.manage',
            'update' => 'permission:students.manage',
            'destroy' => 'permission:students.manage',
        ]);

        // Members roster export.
        Route::get('students-export', [StudentController::class, 'export'])
            ->name('students.export')
            ->middleware('permission:exports.download');

        Route::resource('deposits', DepositController::class)
            ->only(['index', 'create', 'store', 'update'])
            ->middleware('permission:meals.deposit');

        // Reverse a deposit: keeps the row, flags it and posts a counter entry.
        Route::patch('deposits/{deposit}/reverse', [DepositController::class, 'reverse'])
            ->name('deposits.reverse')
            ->middleware('permission:meals.deposit');
        Route::get('deposits/export', [DepositController::class, 'export'])
            ->name('deposits.export')
            ->middleware('permission:exports.download');

        Route::resource('entries', MealEntryController::class)->only(['index', 'create', 'store'])->middleware('permission:meals.entry');
        Route::resource('expenses', MealExpenseController::class)
            ->only(['index', 'create', 'store', 'update'])
            ->middleware('permission:meals.expense');

        // Reverse a recorded expense: keeps the row, flags it, posts a cash-in.
        Route::patch('expenses/{expense}/reverse', [MealExpenseController::class, 'reverse'])
            ->name('expenses.reverse')
            ->middleware('permission:meals.expense');

        Route::get('reports', [MealReportController::class, 'index'])->name('reports.index')->middleware('permission:meals.reports');
        Route::get('reports/export', [MealReportController::class, 'export'])
            ->name('reports.export')
            ->middleware('permission:exports.download');

        // Institutional subsidies - funds from university/company/college, kept
        // distinct from personal deposits.
        Route::get('subsidies', [SubsidyController::class, 'index'])
            ->name('subsidies.index')->middleware('permission:subsidies.view');
        Route::post('subsidies', [SubsidyController::class, 'store'])
            ->name('subsidies.store')->middleware('permission:subsidies.manage');
        Route::patch('subsidies/{subsidy}/reverse', [SubsidyController::class, 'reverse'])
            ->name('subsidies.reverse')->middleware('permission:subsidies.manage');

        // Vendors / suppliers (includes the institution as hub vendor).
        Route::get('vendors/export', [VendorController::class, 'export'])
            ->name('vendors.export')
            ->middleware('permission:exports.download');

        // Purchase history for one vendor (loaded by the profile tab).
        Route::get('vendors/{vendor}/history', [VendorController::class, 'history'])
            ->name('vendors.history')
            ->middleware('permission:vendors.view');

        Route::resource('vendors', VendorController::class)
            ->except(['create', 'edit', 'show'])
            ->middleware([
                'index' => 'permission:vendors.view',
                'store' => 'permission:vendors.manage',
                'update' => 'permission:vendors.manage',
                'destroy' => 'permission:vendors.manage',
            ]);

        // Member invitations: send a signed link so the member sets their own
        // password rather than receiving a default one.
        Route::post('students/{student}/invite', [StudentController::class, 'invite'])
            ->name('students.invite')
            ->middleware('permission:students.invite');
    });
});

require __DIR__ . '/auth.php';
