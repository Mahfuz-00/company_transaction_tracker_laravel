<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MemberDashboardController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
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
use App\Http\Controllers\SmtpSettingsController;
use App\Http\Controllers\Meals\DepartmentController;
use App\Http\Controllers\Meals\StudentController;
use App\Http\Controllers\Meals\DepositController;
use App\Http\Controllers\Meals\MealEntryController;
use App\Http\Controllers\Meals\MealExpenseController;
use App\Http\Controllers\Meals\MealReportController;
use App\Http\Controllers\Meals\RefundController;
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

/*
 * THE SUPPORT ASSISTANT - PUBLIC.
 *
 * Deliberately OUTSIDE the auth group. The assistant is most useful at exactly the
 * moment a visitor has a question and no account: on the landing page. Requiring a
 * sign-in to ask "what does this cost?" would lose the enquiry.
 *
 * The controller owns the abuse rules (per-IP rate limiting and a thread token),
 * and a guest's conversation is keyed by a random token rather than a sequential
 * id, so conversations are not enumerable.
 */
Route::post('/assistant/ask', [\App\Http\Controllers\AssistantController::class, 'ask'])
    ->middleware('throttle:30,1')
    ->name('assistant.ask');

Route::post('/assistant/messages/{message}/flag', [\App\Http\Controllers\AssistantController::class, 'flag'])
    ->middleware('throttle:30,1')
    ->name('assistant.flag');

Route::post('/assistant/messages/{message}/helpful', [\App\Http\Controllers\AssistantController::class, 'helpful'])
    ->middleware('throttle:30,1')
    ->name('assistant.helpful');

/*
 * LANGUAGE SWITCHING FOR A GUEST.
 *
 * The landing page and the auth screens are public, so a visitor must be able to
 * pick a language before they have an account. This writes the SESSION only
 * (there is no user row yet); once they sign in, their choice is persisted to
 * `users.locale` by POST /language.
 *
 * A GET, because switching language is not a state-changing action worth a CSRF
 * round-trip - it is a preference, exactly like following a link.
 */
Route::get('/language/{locale}', [\App\Http\Controllers\LanguageController::class, 'setGuest'])
    ->name('language.guest');

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
        /*
         * MEMBER-INITIATED PAYMENTS. A member records a payment intent here; it is
         * PENDING until a manager verifies it, so this route can never move the
         * balance on its own.
         */
        Route::get('/payments', [\App\Http\Controllers\MemberPaymentController::class, 'index'])->name('payments');
        Route::post('/payments', [\App\Http\Controllers\MemberPaymentController::class, 'store'])->name('payments.store');
    });

    /*
     * MEAL MENU VOTING.
     *
     * DELIBERATELY OUTSIDE the `role:Member` group above.
     *
     * Eligibility is not the same thing as the Member role: an Institution Admin
     * or Meal Manager who eats in the mess may also vote (that is the whole point
     * of the `is_meal_participant` opt-in). Gating this behind `role:Member` would
     * have made an opted-in manager's vote impossible - the 403 this routing fixes.
     *
     * The controller therefore owns the eligibility check
     * (MealMenuController::isEligibleVoter), and refuses anyone who is not a member
     * and has not opted in.
     */
    Route::middleware(['permission:meals.view'])->prefix('my')->name('member.')->group(function () {
        Route::get('/menus', [\App\Http\Controllers\Meals\MealMenuController::class, 'myMenus'])->name('menus');
        Route::post('/menus/{mealMenu}/vote', [\App\Http\Controllers\Meals\MealMenuController::class, 'vote'])->name('menus.vote');
    });

    // Forced / voluntary password change. Reachable even while a user still
    // holds a temporary password (the middleware allow-lists these names).
    Route::get('/password/change', [ProfileController::class, 'showChangePassword'])->name('password.change');
    Route::put('/password/change', [ProfileController::class, 'updatePassword'])->name('password.change.update');

    // Unlink an external SSO identity from the signed-in account. Refused when it
    // is the account's only sign-in method (that would lock the user out).
    Route::delete('/profile/social/{provider}', [\App\Http\Controllers\Auth\SocialAuthController::class, 'unlink'])
        ->name('oauth.unlink');

    /*
     * FIRST-TIME ONBOARDING. Every authenticated role sees a role-specific guide
     * on first login; these endpoints record that it was seen and let the user
     * replay it later. The CONTENT is delivered via shared Inertia props.
     */
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::post('/onboarding/replay', [OnboardingController::class, 'replay'])->name('onboarding.replay');
    Route::get('/onboarding/guide', [OnboardingController::class, 'show'])->name('onboarding.guide');

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

    /*
     * THE SUPPORT ASSISTANT - AUTHENTICATED.
     *
     * `history` returns the signed-in user's own conversation so reopening the
     * panel continues where they left off. The ask/flag routes above are public and
     * work for a signed-in user too, resolving their thread from their account.
     */
    Route::get('/assistant/history', [\App\Http\Controllers\AssistantController::class, 'history'])
        ->name('assistant.history');
    Route::get('/notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::post('/notifications/announce', [NotificationController::class, 'announce']) ->name('notifications.announce');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    /*
     * LANGUAGE SWITCHING.
     *
     * No permission gate: choosing a language is a personal preference, like the
     * theme. The controller validates the code against the shipped catalogue, so
     * a crafted request cannot write an arbitrary locale onto the user row.
     */
    Route::post('/language', [LanguageController::class, 'update'])->name('language.update');

    /*
     * BUG REPORTS - the FILING side.
     *
     * Every tenant role may report a defect (Member, Meal Manager, Institution
     * Admin). The Software Super Admin is deliberately EXCLUDED: they are the
     * recipient of these reports, so filing one would be circular. The controller
     * re-asserts that rule, so it survives any future route reshuffle.
     *
     * There is no matching READ route here on purpose - the inbox lives under
     * /platform (SSA-only), because a defect in one workspace is not another
     * workspace's business.
     */
    Route::post('/bug-reports', [BugReportController::class, 'store'])
        ->name('bug-reports.store');

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

    // Member claims & disputes.
    Route::get('/claims', [ClaimController::class, 'index'])
        ->name('claims.index')
        ->middleware(['permission:claims.view', 'role:Member']);
    Route::post('/claims', [ClaimController::class, 'store'])
        ->name('claims.store')
        ->middleware('permission:claims.submit');

    // Member Meal Schedules (Off/On notifications).
    Route::get('/meals/schedules', [\App\Http\Controllers\Meals\MealScheduleController::class, 'index'])
        ->name('meals.schedules.index');
    Route::post('/meals/schedules', [\App\Http\Controllers\Meals\MealScheduleController::class, 'store'])
        ->name('meals.schedules.store');
    Route::get('/meals/schedules/review', [\App\Http\Controllers\Meals\MealScheduleController::class, 'review'])
        ->name('meals.schedules.review');
    Route::patch('/meals/schedules/{mealSchedule}/acknowledge', [\App\Http\Controllers\Meals\MealScheduleController::class, 'acknowledge'])
        ->name('meals.schedules.acknowledge');

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
     * INSTITUTION SUBSCRIPTION PAYMENTS.
     *
     * An Institution Admin pays the platform fee from their own dashboard; the
     * Software Super Admin verifies it. The submission is PENDING until verified,
     * so an unverified claim can never silently extend access.
     */
    Route::get('/settings/subscription', [\App\Http\Controllers\SubscriptionPaymentController::class, 'show'])
        ->name('settings.subscription.show')
        ->middleware('permission:institution.view');
    Route::post('/settings/subscription/payments', [\App\Http\Controllers\SubscriptionPaymentController::class, 'store'])
        ->name('settings.subscription.store')
        ->middleware('permission:institution.view');

    // Platform-side verification queue (SSA only; re-asserted in the controller).
    Route::get('/platform/subscription-payments', [\App\Http\Controllers\SubscriptionPaymentController::class, 'queue'])
        ->name('ssa.subscription-payments.index')
        ->middleware('role:Software Super Admin');
    Route::patch('/platform/subscription-payments/{subscriptionPayment}/approve', [\App\Http\Controllers\SubscriptionPaymentController::class, 'approve'])
        ->name('ssa.subscription-payments.approve')
        ->middleware('role:Software Super Admin');
    Route::patch('/platform/subscription-payments/{subscriptionPayment}/reject', [\App\Http\Controllers\SubscriptionPaymentController::class, 'reject'])
        ->name('ssa.subscription-payments.reject')
        ->middleware('role:Software Super Admin');

    /*
     * MULTI-CURRENCY / FX RATE SNAPSHOTS - SSA only.
     *
     * An exchange rate is a PLATFORM-WIDE fact, not a workspace setting: it exists
     * so the SSA can report revenue across institutions that bill in different
     * currencies. Only the global role may read or write the rate book.
     */
    Route::get('/platform/currencies', [\App\Http\Controllers\FxRateController::class, 'index'])
        ->name('ssa.currencies.index')
        ->middleware('role:Software Super Admin');
    Route::post('/platform/currencies', [\App\Http\Controllers\FxRateController::class, 'store'])
        ->name('ssa.currencies.store')
        ->middleware('role:Software Super Admin');
    Route::delete('/platform/currencies/{fxRate}', [\App\Http\Controllers\FxRateController::class, 'destroy'])
        ->name('ssa.currencies.destroy')
        ->middleware('role:Software Super Admin');

    /*
     * Theme Customizer - a dedicated settings sub-module available to EVERY
     * authenticated user (member included). It is deliberately NOT gated by a
     * permission: personalising one's own view is a personal preference, not an
     * administrative act. Each user only ever edits their OWN `users.theme`.
     */
    Route::get('/settings/theme', [ThemeController::class, 'edit'])->name('settings.theme.edit');
    Route::put('/settings/theme', [ThemeController::class, 'update'])->name('settings.theme.update');
    Route::post('/settings/theme/reset', [ThemeController::class, 'reset'])->name('settings.theme.reset');

    /*
     * PERSONAL PREFERENCES.
     *
     * Where the theme customiser stores HOW the product looks, this stores HOW it
     * behaves FOR THIS PERSON. The only preference so far is the global hint/tooltip
     * switch: one place to silence every `?` badge on the platform.
     *
     * Like the theme, it is deliberately ungated - every role (member included) owns
     * their own guidance preference - and the controller scopes the write to
     * `$request->user()`, so one account can never edit another's.
     */
    Route::post('/settings/hints', [\App\Http\Controllers\UserPreferenceController::class, 'updateHints'])
        ->name('settings.hints.update');

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
     */    Route::get('/platform/enquiries', [LandingEnquiryController::class, 'index'])
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

    /*
     * BUG REPORTS - the TRIAGE side (SSA ONLY).
     *
     * The queue spans EVERY institution, so it carries the global permission a
     * tenant role never holds. Both routes are additionally gated on the role,
     * and the controller is not tenant-scoped for this model (by design - see
     * App\Models\BugReport).
     */
    Route::get('/platform/bug-reports', [BugReportController::class, 'index'])
        ->name('ssa.bug-reports.index')
        ->middleware('role:Software Super Admin');

    /*
     * THE SUPPORT ASSISTANT'S ESCALATION QUEUE - SSA only.
     *
     * This is where the assistant LEARNS: answering an escalation writes a new
     * corpus row, so the same question is handled autonomously next time. Gated on
     * the global role (and re-asserted by the controller's own model scoping), so
     * no tenant role can read another workspace's questions.
     */
    Route::get('/platform/assistant', [\App\Http\Controllers\AssistantQueueController::class, 'index'])
        ->name('ssa.assistant.index')
        ->middleware('role:Software Super Admin');
    Route::patch('/platform/assistant/{escalation}', [\App\Http\Controllers\AssistantQueueController::class, 'update'])
        ->name('ssa.assistant.update')
        ->middleware('role:Software Super Admin');
    Route::post('/platform/assistant/knowledge', [\App\Http\Controllers\AssistantQueueController::class, 'storeKnowledge'])
        ->name('ssa.assistant.knowledge.store')
        ->middleware('role:Software Super Admin');
    Route::patch('/platform/bug-reports/{bugReport}', [BugReportController::class, 'update'])
        ->name('ssa.bug-reports.update')
        ->middleware('role:Software Super Admin');
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
     * PLATFORM SMTP / MAIL SETTINGS - Software Super Admin ONLY.
     *
     * A dedicated settings sub-module that lets the SSA point the whole platform
     * at an SMTP relay (pre-filled with the production Brevo relay), and send a
     * test email, with no redeploy. Gated by the global role at the route AND
     * re-asserted in every controller action, so no institution-scoped role can
     * ever read or change the platform's mail relay.
     */
    Route::get('/platform/smtp', [SmtpSettingsController::class, 'edit'])
        ->name('ssa.smtp.edit')
        ->middleware('role:Software Super Admin');
    Route::put('/platform/smtp', [SmtpSettingsController::class, 'update'])
        ->name('ssa.smtp.update')
        ->middleware('role:Software Super Admin');
    Route::post('/platform/smtp/test', [SmtpSettingsController::class, 'sendTest'])
        ->name('ssa.smtp.test')
        ->middleware('role:Software Super Admin');
    // Live connection check (ping) - verifies the relay actually accepts the
    // credentials without saving or sending anything.
    Route::post('/platform/smtp/check', [SmtpSettingsController::class, 'check'])
        ->name('ssa.smtp.check')
        ->middleware('role:Software Super Admin');

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

        /*
         * REFUNDS - money paid back OUT of a member's meal balance when they
         * stop meals or withdraw funds. Gated by `meals.deposit`, the same
         * permission the Deposit module uses, so whoever can take money in can
         * pay it back out (Institution Admin + Meal Manager; managers scoped to
         * their assigned members in the controller).
         */
        Route::get('refunds', [RefundController::class, 'index'])
            ->name('refunds.index')
            ->middleware('permission:meals.deposit');
        Route::post('refunds', [RefundController::class, 'store'])
            ->name('refunds.store')
            ->middleware('permission:meals.deposit');
        Route::patch('refunds/{refund}/reverse', [RefundController::class, 'reverse'])
            ->name('refunds.reverse')
            ->middleware('permission:meals.deposit');

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

        /*
         * BULK IMPORT (members / opening balances / historical meals).
         * The dedicated `import` permission keeps this heavy, write-capable module
         * off a read-only account.
         */
        Route::get('import', [\App\Http\Controllers\Meals\ImportController::class, 'index'])
            ->name('import.index')->middleware('permission:students.manage');
        Route::post('import/analyse', [\App\Http\Controllers\Meals\ImportController::class, 'analyse'])
            ->name('import.analyse')->middleware('permission:students.manage');
        Route::post('import/commit', [\App\Http\Controllers\Meals\ImportController::class, 'commit'])
            ->name('import.commit')->middleware('permission:students.manage');
        Route::get('import/template/{dataset}', [\App\Http\Controllers\Meals\ImportController::class, 'template'])
            ->name('import.template')->middleware('permission:students.manage');

        /*
         * ANOMALY MONITOR - flag duplicate deposits, meal spikes, negative
         * balances and unusual expenses for an administrator to review.
         */
        Route::get('anomalies', [\App\Http\Controllers\Meals\AnomalyController::class, 'index'])
            ->name('anomalies.index')->middleware('permission:meals.reports');
        Route::post('anomalies/scan', [\App\Http\Controllers\Meals\AnomalyController::class, 'scan'])
            ->name('anomalies.scan')->middleware('permission:meals.reports');
        Route::patch('anomalies/{anomaly}/review', [\App\Http\Controllers\Meals\AnomalyController::class, 'review'])
            ->name('anomalies.review')->middleware('permission:meals.reports');

        /*
         * DYNAMIC REPORTS BUILDER - compose, save and re-run reports over the
         * finance engine. Gated by meals.reports (the same right as the fixed
         * reports it supplements).
         */
        Route::get('report-builder', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'index'])
            ->name('report-builder.index')->middleware('permission:meals.reports');
        Route::post('report-builder/run', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'run'])
            ->name('report-builder.run')->middleware('permission:meals.reports');
        Route::post('report-builder', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'store'])
            ->name('report-builder.store')->middleware('permission:meals.reports');
        Route::put('report-builder/{savedReport}', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'update'])
            ->name('report-builder.update')->middleware('permission:meals.reports');
        Route::delete('report-builder/{savedReport}', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'destroy'])
            ->name('report-builder.destroy')->middleware('permission:meals.reports');
        Route::get('report-builder/{savedReport}/run', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'runSaved'])
            ->name('report-builder.run-saved')->middleware('permission:meals.reports');
        Route::match(['get', 'post'], 'report-builder/export', [\App\Http\Controllers\Meals\ReportBuilderController::class, 'export'])
            ->name('report-builder.export')->middleware('permission:meals.reports');

        /*
         * MENU CYCLE & PROCUREMENT FORECASTS. Managing the menu is a planning act
         * (meals.reports); the forecasts it produces drive purchasing decisions.
         */
        Route::get('menu-cycle', [\App\Http\Controllers\Meals\MenuCycleController::class, 'index'])
            ->name('menu-cycle.index')->middleware('permission:meals.reports');
        Route::post('menu-cycle', [\App\Http\Controllers\Meals\MenuCycleController::class, 'store'])
            ->name('menu-cycle.store')->middleware('permission:meals.reports');
        Route::put('menu-cycle/{menuCycle}', [\App\Http\Controllers\Meals\MenuCycleController::class, 'update'])
            ->name('menu-cycle.update')->middleware('permission:meals.reports');
        Route::delete('menu-cycle/{menuCycle}', [\App\Http\Controllers\Meals\MenuCycleController::class, 'destroy'])
            ->name('menu-cycle.destroy')->middleware('permission:meals.reports');
        Route::put('menu-cycle/{menuCycle}/days/{dayNumber}', [\App\Http\Controllers\Meals\MenuCycleController::class, 'updateDay'])
            ->name('menu-cycle.days.update')->middleware('permission:meals.reports');
        Route::post('menu-cycle/{menuCycle}/ingredients', [\App\Http\Controllers\Meals\MenuCycleController::class, 'storeIngredient'])
            ->name('menu-cycle.ingredients.store')->middleware('permission:meals.reports');
        Route::delete('menu-cycle/ingredients/{ingredient}', [\App\Http\Controllers\Meals\MenuCycleController::class, 'destroyIngredient'])
            ->name('menu-cycle.ingredients.destroy')->middleware('permission:meals.reports');

        /*
         * PURCHASE ORDERS, GOODS RECEIPTS & INVOICES (3-way match). Raising a PO is
         * a purchasing act (meals.expense); APPROVING one is restricted inside the
         * controller to an Institution Admin.
         */
        Route::get('purchase-orders', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'index'])
            ->name('purchase-orders.index')->middleware('permission:meals.expense');
        Route::post('purchase-orders', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'store'])
            ->name('purchase-orders.store')->middleware('permission:meals.expense');
        Route::get('purchase-orders/{purchaseOrder}', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'show'])
            ->name('purchase-orders.show')->middleware('permission:meals.expense');
        Route::patch('purchase-orders/{purchaseOrder}/approve', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'approve'])
            ->name('purchase-orders.approve')->middleware('permission:meals.expense');
        Route::patch('purchase-orders/{purchaseOrder}/status', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'updateStatus'])
            ->name('purchase-orders.status')->middleware('permission:meals.expense');
        Route::post('purchase-orders/{purchaseOrder}/receipts', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'storeReceipt'])
            ->name('purchase-orders.receipts.store')->middleware('permission:meals.expense');
        Route::post('purchase-orders/{purchaseOrder}/invoices', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'storeInvoice'])
            ->name('purchase-orders.invoices.store')->middleware('permission:meals.expense');
        Route::post('purchase-orders/invoices/{vendorInvoice}/match', [\App\Http\Controllers\Meals\PurchaseOrderController::class, 'rematch'])
            ->name('purchase-orders.invoices.match')->middleware('permission:meals.expense');

        /*
         * AI FORECASTING (RAG). Viewing uses the reporting right; rebuilding the
         * vector corpus is a heavier maintenance act, so it is grouped with it
         * rather than exposed to every reader.
         */
        Route::get('forecasting', [\App\Http\Controllers\Meals\ForecastingController::class, 'index'])
            ->name('forecasting.index')->middleware('permission:meals.reports');
        Route::post('forecasting/embed', [\App\Http\Controllers\Meals\ForecastingController::class, 'embed'])
            ->name('forecasting.embed')->middleware('permission:meals.reports');
        Route::post('forecasting/benchmarks', [\App\Http\Controllers\Meals\ForecastingController::class, 'storeBenchmark'])
            ->name('forecasting.benchmarks.store')->middleware('permission:meals.reports');

        /*
         * MEMBER-INITIATED PAYMENTS - the VERIFICATION queue. A manager confirms a
         * payment the member submitted; only then does a real deposit exist.
         */
        Route::get('member-payments', [\App\Http\Controllers\MemberPaymentController::class, 'queue'])
            ->name('member-payments.index')->middleware('permission:meals.deposit');
        Route::patch('member-payments/{memberPayment}/approve', [\App\Http\Controllers\MemberPaymentController::class, 'approve'])
            ->name('member-payments.approve')->middleware('permission:meals.deposit');
        Route::patch('member-payments/{memberPayment}/reject', [\App\Http\Controllers\MemberPaymentController::class, 'reject'])
            ->name('member-payments.reject')->middleware('permission:meals.deposit');

        /*
         * MEAL MENU & VOTING.
         *
         * Staff PROPOSE a menu and its options; eligible members vote; an Admin or
         * Meal Manager APPROVES it before it becomes active. Proposing is a
         * management act (meals.reports); voting itself is open to every eligible
         * member and lives under /my/menus below.
         */
        Route::get('menus', [\App\Http\Controllers\Meals\MealMenuController::class, 'index'])
            ->name('menus.index')->middleware('permission:meals.reports');
        Route::post('menus', [\App\Http\Controllers\Meals\MealMenuController::class, 'store'])
            ->name('menus.store')->middleware('permission:meals.reports');
        Route::get('menus/{mealMenu}', [\App\Http\Controllers\Meals\MealMenuController::class, 'show'])
            ->name('menus.show')->middleware('permission:meals.reports');
        Route::post('menus/{mealMenu}/options', [\App\Http\Controllers\Meals\MealMenuController::class, 'storeOption'])
            ->name('menus.options.store')->middleware('permission:meals.reports');
        Route::delete('menus/options/{option}', [\App\Http\Controllers\Meals\MealMenuController::class, 'destroyOption'])
            ->name('menus.options.destroy')->middleware('permission:meals.reports');
        Route::patch('menus/{mealMenu}/open', [\App\Http\Controllers\Meals\MealMenuController::class, 'openVoting'])
            ->name('menus.open')->middleware('permission:meals.reports');
        // APPROVAL is re-asserted in the controller to an Admin / Meal Manager.
        Route::patch('menus/{mealMenu}/approve', [\App\Http\Controllers\Meals\MealMenuController::class, 'approve'])
            ->name('menus.approve')->middleware('permission:meals.reports');
        Route::patch('menus/{mealMenu}/reject', [\App\Http\Controllers\Meals\MealMenuController::class, 'reject'])
            ->name('menus.reject')->middleware('permission:meals.reports');
        Route::patch('menus/{mealMenu}/cancel', [\App\Http\Controllers\Meals\MealMenuController::class, 'cancel'])
            ->name('menus.cancel')->middleware('permission:meals.reports');

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
