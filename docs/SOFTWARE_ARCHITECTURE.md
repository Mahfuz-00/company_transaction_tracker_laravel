# Software Architecture (Deep Reference)

> **This document supersedes `docs/SOFTWARE.md`.** It is the onboarding
> reference for anyone joining this codebase: it explains the stack, the
> multi-tenancy model, every layer of the request lifecycle, the data model,
> the money maths, the theme engine, the mobile API, and how to test it all.
>
> It is written to be accurate against the code — every class, method and column
> named here exists as spelled. When code and doc disagree, the code wins; fix
> the doc.

---

## Table of contents

1. [Stack & high-level architecture](#1-stack--high-level-architecture)
2. [Multi-tenancy](#2-multi-tenancy)
3. [Request lifecycle & middleware stack](#3-request-lifecycle--middleware-stack)
4. [Domain model & database schema](#4-domain-model--database-schema)
5. [The maths — `FinanceCalculator`](#5-the-maths--financecalculator)
6. [The theme engine](#6-the-theme-engine)
7. [The mobile API layer](#7-the-mobile-api-layer)
8. [Frontend architecture (Inertia + React)](#8-frontend-architecture-inertia--react)
9. [Testing & verification](#9-testing--verification)
10. [Directory map, adding a feature & gotchas](#10-directory-map-adding-a-feature--gotchas)

---

## 1. Stack & high-level architecture

This is a **Laravel 12 monolith with an Inertia.js/React front end**. There is
no separate SPA server and no second API for the web UI: a controller returns
`Inertia::render('Page/Name', $props)`, and Inertia hands that React component
those props as its page props. The front end is built by Vite and lives under
`resources/js`.

| Layer | Technology | Where |
|---|---|---|
| Backend framework | **Laravel 12**, PHP `^8.2` | `app/`, `bootstrap/`, `routes/` |
| Inertia (server) | `inertiajs/inertia-laravel` `^2.0` | `app/Http/Middleware/HandleInertiaRequests.php` |
| Frontend | **React 18** + **`@inertiajs/react` `^2.0`** | `resources/js/` |
| Bundler | Vite `^7` via `laravel-vite-plugin` `^2`, `@vitejs/plugin-react` | `vite.config.js` |
| Styling | Tailwind CSS `^3.2` + `@tailwindcss/forms` (+ `@tailwindcss/vite` for CSS) | `tailwind.config.js`, `resources/css/app.css` |
| Charts | Chart.js `^4` via `react-chartjs-2` `^5` | `resources/js/Pages/**` |
| Web auth | Session (cookie) guard `web` | `config/auth.php`, `routes/auth.php` |
| Mobile/API auth | **Laravel Sanctum** `^4` personal access tokens | `config/sanctum.php`, `routes/api.php` |
| Roles & permissions | **`spatie/laravel-permission` `^6.25`** | `database/seeders/RolesAndPermissionsSeeder.php` |
| Named routes in JS | **Ziggy** (`tightenco/ziggy` `^2`) `route()` helper | injected via `HandleInertiaRequests` |
| Database | SQLite in dev; MySQL/Postgres in prod (`DB_CONNECTION`) | `database/migrations/*` |
| Tests | PHPUnit `^11`, Laravel Dusk `^8.6` | `phpunit.xml`, `phpunit.dusk.xml`, `tests/` |

### The monolith at a glance

```
                         ┌──────────────────────────── HTTP ────────────────────────────┐
                         │                                                               │
   browser  ──────────► │  routes/web.php ──► web middleware group ──► Controller        │
 (session cookie)        │                        │                            │         │
                         │                        │                    Inertia::render     │
                         │                        │                            │         │
                         │                        └── HandleInertiaRequests ──► Page props │
                         │                                                               │
   mobile app ────────► │  routes/api.php ──► auth:sanctum ──► Api Controller ──► JSON    │
 (Bearer token)          │                                                               │
                         └───────────────────────────────────────────────────────────────┘
                                     │                         ▲
                                     ▼                         │
                              Eloquent models  ◄──── BelongsToInstitution global scope
                              (TenantManager reads active tenant)
```

**Why Inertia instead of a JSON API for the web UI?** The web controllers keep
Laravel's routing, middleware, validation and redirects; the React layer is a
rendering concern only. The mobile client, which cannot use session cookies, is
served by the *same models and services* through the JSON layer in
`app/Http/Controllers/Api/*` — only the response format differs.

### Two access surfaces, one domain

- **Web** → `routes/web.php`, session-authenticated, returns Inertia pages.
- **Mobile** → `routes/api.php`, Sanctum bearer tokens, returns `{ data, meta }`.

Both go through `app/Support/FinanceCalculator.php` for money and both are
constrained by the tenant scope (Section 2). This is the central design rule:
**the format differs, the truth does not.**

---

## 2. Multi-tenancy

The tenant is an **`Institution`** (a dorm, hall, office canteen or mess). One
deployment can host many. The entire isolation model rests on four pieces that
work together:

| Piece | File | Job |
|---|---|---|
| `TenantManager` | `app/Support/TenantManager.php` | Answers *"which tenant is this request acting inside, and may it see across tenants?"* |
| `BelongsToInstitution` | `app/Models/Concerns/BelongsToInstitution.php` | Global scope + auto-stamp on every tenant-owned model |
| `ResolveTenant` | `app/Http/Middleware/ResolveTenant.php` | Validates + pins the tenant for the request |
| `Institution::current()` / `sessionTenantId()` | `app/Models/Institution.php` | Resolves the human-facing active institution |

`TenantManager` is registered as a **per-request singleton** in
`AppServiceProvider::register()`:

```php
$this->app->singleton(TenantManager::class, fn () => new TenantManager());
```

One instance per request keeps the resolve cheap and makes the "active tenant"
consistent everywhere inside that request.

### 2.1 The three contexts

`TenantManager::resolveTenantId()` returns a single value that decides
everything:

```
 resolveTenantId()  ┌── null ──► GLOBAL context
                    │            • Software Super Admin on the platform view
                    │            • console / queue / guest with no user
                    │            • global scope adds NO WHERE clause
                    │
                    └── int  ──► SCOPED context
                                 • Institution Admin / Meal Manager / Member
                                 • an SSA who switched into one institution
                                 • every tenant query filtered to that id
```

The resolution order inside `resolveTenantId()`:

1. A **forced** id (`$this->forcedTenantId`) wins over everything — this is how a
   service pins its own queries (see `FinanceCalculator::scoped()`).
2. If in **global mode** (`$this->globalMode`), return `null`.
3. If the user is a **Super Admin**, return `Institution::sessionTenantId()`
   (null unless they switched in).
4. Otherwise a bound user returns **their own `institution_id`** — they can
   *never* produce a null (global) context.
5. Console/queue/guest fall back to `Institution::current()?->id`.

### 2.2 `BelongsToInstitution` — scope, stamp, escape hatch

Every model whose rows belong to one institution `use`s this trait. On boot
(`bootBelongsToInstitution()`) it does three automatic things:

**1. Global scope** — an *institution* global scope is attached. It asks the
manager for the id:

```php
static::addGlobalScope('institution', function ($query) {
    $tenantId = app(TenantManager::class)->resolveTenantId();

    if ($tenantId === null) {
        return; // GLOBAL context: no clause, SSA sees everything
    }

    $query->where($query->getModel()->getTable() . '.institution_id', $tenantId);
});
```

Because this runs on *every* query through the model — including relationship
loads and aggregates — a controller **cannot forget to scope**. A bare
`Student::all()` inside a scoped request returns only that institution's rows.

**2. Auto-stamping** — on `creating`, a `NULL` `institution_id` is filled from
the active tenant, so a new row can never be orphaned:

```php
static::creating(function ($model) {
    if ($model->getAttribute('institution_id') === null) {
        $tenantId = app(TenantManager::class)->resolveTenantId();
        if ($tenantId !== null) {
            $model->setAttribute('institution_id', $tenantId);
        }
    }
});
```

**3. Explicit escape hatches** — greppable, deliberate lifts of isolation:

- `Model::withoutTenantScope()` → `withoutGlobalScope('institution')`. **Only** for
  SSA platform-wide aggregation. Every use is a review checkpoint.
- `Model::forInstitution($query, $institutionId)` → `withoutTenantScope()` then
  `where('institution_id', $institutionId)`. Used by SSA screens that iterate
  many institutions in one request and must pin each query to the row's own
  institution.

> **NULL-safety.** Pre-multi-tenancy rows have `institution_id = NULL`. A
> global-context query returns them (so the SSA can find and assign them), while
> a scoped query does not — legacy rows are visible to the platform, never to a
> specific institution.

### 2.3 `ResolveTenant` middleware

`ResolveTenant` runs on the web group (Section 3). It does **not** filter
queries — it *validates and pins* the tenant so the scope is always correct:

1. **Drop a stale session tenant.** If `session('tenant_id')` points at a deleted
   institution, forget it so `Institution::current()` falls back to the user's own
   institution instead of resolving nothing (a classic source of 404s).
2. **Refuse cross-tenant access.** If the session tenant is not one the user may
   access (see `mayAccessTenant()` below), forget it.
3. **Publish the id** on `$request->attributes->set('tenant_id', $resolved)` for
   controllers/logging.
4. **Pin it onto the manager** — the linchpin:

```php
if ($resolved === null && $user->isSuperAdmin()) {
    $manager->withoutScope();      // SSA platform view: cross-tenant by design
} else {
    $manager->force($resolved);    // scoped: every model query filters to it
}
```

```php
protected function mayAccessTenant($user, Institution $tenant): bool
{
    if ($user->isSuperAdmin()) {
        return true;              // SSA may view any workspace
    }
    return $user->institution_id !== null
        && (int) $user->institution_id === (int) $tenant->id;   // bound users: own only
}
```

`$resolved` is `null` **only** for an SSA who has not switched in. A bound user
always has a non-null `institution_id`, so they can never reach the unscoped
context.

### 2.4 `runGlobally()` / `withTenant()` — scoped exceptions

Two state-restoring helpers let a service temporarily change the context without
leaking it into the surrounding query stream. Both capture the previous
`globalMode`/`forcedTenantId`, apply the override, and restore in a `finally`:

```php
// Lift the scope for one block — SSA registry that must roll up EVERY tenant.
$rows = $manager->runGlobally(fn () => Institution::query()->withCount('students')->get());

// Pin to ONE tenant inside an otherwise-global request.
$figures = $manager->withTenant($institution->id, fn () => $calculator->monthSnapshot($month));
```

`FinanceCalculator::scoped()` wraps every aggregate in `withTenant($this->institution->id, …)`.
That is why the SSA registry can build a `new FinanceCalculator($institution)` for
each row and get that institution's own figures — never a platform-wide blend —
even while the request is global.

### 2.5 The SSA institution switch

Switching is **session-scoped, per-user, and never mutates the DB**. In
`InstitutionRegistryController::switchTo()`:

```php
if (! $user->isSuperAdmin()) { /* refused */ }

$request->session()->put('tenant_id', $institution->id);
$request->session()->save();
```

`exitTenant()` does `session()->forget('tenant_id')`. When the session tenant is
set, `Institution::current()` returns it (priority 1), `ResolveTenant` pins it, and
every tenant model filters to it. `HandleInertiaRequests` shares a `tenant` prop:

```php
'tenant' => [
    'active_id'  => $institution?->id,
    'switched'   => Institution::sessionTenantId() !== null
                    && Institution::sessionTenantId() !== $request->user()?->institution_id,
    'can_switch' => (bool) $request->user()?->isSuperAdmin(),
],
```

`AuthenticatedLayout` shows an amber "Viewing … as a switched workspace" banner
with an **Exit to platform view** button whenever `tenant.switched` is true. The
nav (`resources/js/Utils/navItems.js`) uses the same flag: `tenantScoped` sections
(Meal Management, Workspace Settings) are normally hidden from the SSA, but are
revealed once `switched === true`.

> **Why the DB isn't touched.** An earlier design flipped a single global
> `is_active` column on switch. That column is shared across every user and
> session, so switching deactivated all other institutions and produced 404s /
> mismatched workspaces. The session scope fixes it: each user gets their own
> view, the row is untouched, and two operators can be in different workspaces at
> once.

### 2.6 The leak this design prevents

Documented verbatim at the top of `TenantManager`:

> Before this class, controllers each resolved the active institution with
> `Institution::current()` and then (sometimes) remembered to add a
> `where('institution_id', …)` clause. Any controller that forgot the clause — or
> any query built through a model relationship — happily returned rows from
> **every** institution. That is the cross-tenant leak: expenses, members and
> deposits saved in "Touch and Solve Ltd." showed up in "North South University".

The fix is to make **scoping the default, not an opt-in**. `docs` and migration
comments reinforce this: the `2026_09_25_100000_add_institution_id_to_ledger_tables`
migration denormalised `institution_id` onto `transactions`, `deposits`,
`meal_entries` and `meal_expenses` precisely because those tables previously
reached their institution only *through a joined student* — leaving raw
aggregates with no column to scope on.

---

## 3. Request lifecycle & middleware stack

`bootstrap/app.php` is the whole middleware configuration (Laravel 12 style — no
`Kernel.php`). It registers two groups and a set of aliases.

### 3.1 Web group (appended)

```php
$middleware->web(append: [
    \App\Http\Middleware\ResolveTenant::class,           // pin the tenant FIRST
    \App\Http\Middleware\HandleInertiaRequests::class,   // then share props
    \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    \App\Http\Middleware\EnsurePasswordIsChanged::class, // forced password change
]);
```

Order matters: `ResolveTenant` runs **before** `HandleInertiaRequests`, so the
institution, terminology, currency and theme props it shares are already correct
for the request.

### 3.2 API group (appended)

```php
$middleware->api(append: [
    'throttle:api',   // baseline 60/min; the framework default group is unthrottled
]);
```

The strict per-email `api-login` limiter (5/min) is added on the auth routes
themselves in `routes/api.php` (Section 7).

### 3.3 Route middleware aliases

```php
$middleware->alias([
    'role'              => \Spatie\Permission\Middleware\RoleMiddleware::class,
    'permission'        => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    'role_or_permission'=> \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    'password.changed'  => \App\Http\Middleware\EnsurePasswordIsChanged::class,
    'tenant'            => \App\Http\Middleware\ResolveTenant::class,
]);
```

Usage examples from `routes/web.php`:

```php
->middleware('permission:students.view')                                     // capability
->middleware(['permission:users.view', 'role:Software Super Admin|Institution Admin']) // capability + role
```

### 3.4 `HandleInertiaRequests::share()`

Shares props on **every** response, so no page re-fetches them:

| Prop | Source | Purpose |
|---|---|---|
| `auth.user` | `$request->user()` | id, name, email, `avatar_url`, `designation`, `institution_id`, `is_super_admin`, `theme` (the DB half of persistence) |
| `auth.roles` / `auth.permissions` | Spatie | drives `useCan()` on the client |
| `notifications` | lazy closure | unread count + the latest 6, powers the header bell |
| `flash` | session | `success` / `error` / `status` — without this, `->with('success', …)` was invisible |
| `platform` | `PlatformBranding::toArray()` + `config('platform.*')` | master product name / chrome labels for landing, login and SSA sidebar |
| `tenant` | `Institution::sessionTenantId()` | `active_id`, `switched`, `can_switch` (Section 2.5) |
| `institution` | `Institution::current()` (cached per request) | id, name, subtitle, type, `type_label`, currency, **`terms`** (`terminologyMap()`), logo/banner, `theme`, `accent` |
| `currency` | `institution()->currencySettings()` | formatting rules everywhere |

`institution()` is memoised on the middleware (`$this->institution ??= Institution::current()`)
so all those props share one lookup.

### 3.5 `EnsurePasswordIsChanged`

Runs right after auth. If `$user->mustChangePassword()` is true, every request is
redirected to `password.change` **except** the allow-listed names:

```php
protected array $allowed = ['password.change', 'password.change.update', 'logout'];
```

This is the fallback for admin-provisioned accounts with a temporary ("demo")
password. Once account creation is invite-only, no one is flagged and the
middleware is a no-op.

---

## 4. Domain model & database schema

```
Institution (the tenant)
 ├── User                 belongs to an institution; holds Spatie roles
 ├── Student              the "member" roster record (historical name)
 │    ├── Deposit          money in  (Cash In)
 │    ├── MealEntry        breakfast/lunch/dinner per day
 │    └── Claim            member dispute / "buy something" request
 ├── Department            groups members ("Team"/"Group" by type)
 ├── Vendor                supplier; one row is the institution's own "hub"
 ├── Subsidy + SubsidySource   institutional funding + managed funders
 ├── MealRateSetting       how the per-meal rate is derived
 └── MealExpense           expense detail, linked to a ledger Transaction

Transaction       the shared ledger — every money movement in or out
ActivityLog       immutable audit trail (one row per change)
EmailLog          outbox — every dispatched email, with full rendered body
Notification      in-app notifications (Laravel database notifications)
MemberInvitation  emailed signed signup links
SubscriptionPlan  SSA pricing tiers (platform-level, not tenant-scoped)
LandingEnquiry    public demo requests the SSA can convert (platform-level)
StaffBroadcast    platform-wide announcements (platform-level)
Currency / UserSetting   small lookup/aux tables
```

> **Naming note.** The model is `Student` and the table is `students` for
> historical reasons, but it represents a **member**. The terminology layer maps
> it to the institution's noun (Section 4.3). The API calls it a "member".

### 4.1 Tenant-owned vs platform-level tables

| Tenant-owned (`use BelongsToInstitution`, has `institution_id`) | Platform-level (no trait) |
|---|---|
| `students`, `departments`, `vendors`, `subsidies`, `subsidy_sources`, `meal_rate_settings`, `claims`, `transactions`, `deposits`, `meal_entries`, `meal_expenses`, `activity_logs`, `email_logs`, `users` | `institutions`, `subscription_plans`, `landing_enquiries`, `staff_broadcasts`, `currencies` |

`activity_logs` and `email_logs` carry `institution_id` so both can be scoped per
workspace, but the SSA sees the global stream. `users` carries `institution_id`
(null for SSAs).

### 4.2 Key tables

**`institutions`** — the tenant. Core columns: `name`, `slug`, `invite_code`
(unique, backfilled), `type`, `currency_code`, `currency_settings` (JSON),
`theme` (JSON), `logo_path`, `banner_path`, `subtitle`, `terminology` (JSON),
`settings` (JSON), `subsidy_mode`, `is_active`, plus the SSA monitoring/trial
block: `subscription_plan`, `subscription_status`, `subscription_amount`,
`subscription_started_at`, `subscription_renews_at`, `member_limit`, `health_notes`,
`last_reviewed_at`, `onboarding_mode`, `trial_started_at`, `trial_ends_at`,
`trial_reminder_sent_at`, `converted_at`.

- `Institution::TYPES` = `company`, `university_dorm`, `college_dorm`,
  `general_mess`; each carries a terminology preset.
- `Institution::THEMES` = the 8 accents (`indigo`, `emerald`, `sky`, `violet`,
  `rose`, `amber`, `slate`, `teal`) with hex + soft tints.
- `Institution::current()` priority: session tenant → user's own institution →
  first active institution.
- Routes bind by `slug` (`getRouteKeyName()`), but `resolveRouteBinding()` accepts
  a numeric id too.

**`users`** — stock Laravel columns plus `institution_id`, `phone`, `status`,
`last_login_at`, `avatar_path`, `designation`, `theme` (JSON), `invitation_pending`,
`must_change_password`, `password_changed_at` (nullable `password`),
`setup_completed_at`. `User` uses `HasApiTokens` (Sanctum), `Notifiable`,
`HasRoles` (Spatie). Key methods: `isSuperAdmin()`, `isActive()`,
`mustChangePassword()`, `isMember()`, `themeSettings()`, `avatarUrl()`.
`User::DEFAULT_THEME` = `{ mode: light, accent: indigo, radius: lg, density:
comfortable, font: inter }`.

**`students`** (members) — `user_id` (login, nullable), `manager_id`, `name`,
`roll`, `department_id`, `institution_id`, `join_date`, `status`. Index
`(institution_id, roll)`.

**`departments`** — `institution_id`, `name`, `slug`, `description`.

**`transactions`** — the shared ledger. `user_id`, `student_id` (who paid in),
`vendor_id`, `item`, `type` (`in` | `out`), `category`, `amount`,
`payment_method` (default `'Cash'`), `by_whom`, `payee`, `reason`, `source`
(`manual` | `meal_expense` | `deposit` | …), `institution_id`.
`type='in'` = Cash In, `type='out'` = Cash Out. **All money totals sum here.**

**`deposits`** — Cash In detail. `student_id`, `amount`, `kind`
(`personal` | `subsidy` | `credit`), `subsidy_id`, `payment_method`,
`recorded_by`, `transaction_id`, `institution_id`, and reversal columns
`reversed_at`, `reversed_by`, `reversal_transaction_id`. A deposit writes both a
`deposits` row and a matching `transactions` row in one `DB::transaction()`.

**`meal_entries`** — one row per member per day: `student_id`, `date`,
`breakfast`, `lunch`, `dinner` (small ints), `total_meals` (a SQL generated
column `breakfast + lunch + dinner`), `recorded_by`, `institution_id`. Unique
`(student_id, date)`.

**`meal_expenses`** — expense detail linked to a ledger transaction:
`transaction_id`, `vendor_id`, `description`, `category`, `payment_status`,
`amount`, `recorded_by`, `institution_id`, and reversal columns.

**`vendors`** — suppliers. `institution_id`, `name`, `slug`, `category`,
`is_institution_hub` (the institution acting as its own supplier), `recurrence`,
`lead_time_days`, `recurring_amount`, `opening_balance`, `status`.

**`subsidies`** — institutional funding. `institution_id`, `source`
(`university_authority` | `company_management` | `college_administration` |
`government_grant` | `donation` | `other`), `source_label`, `department_id`,
`student_id`, `apply_mode` (`pool` | `per_member` | `credit_behind`), `amount`,
`period_month` (`YYYY-MM`), `percentage`, `status` (`active` | `reversed`).
Scopes: `active()`, `forMonth($month)`.

**`subsidy_sources`** — admin-managed funders: `institution_id`, `name`, `key`,
`percentage` (default share 0–100), `description`, `is_active`. Unique
`(institution_id, name)`.

**`meal_rate_settings`** — one row per institution: `rate_mode`
(`calculated` | `manual` | `hybrid`), `target_subsidy_ratio` (default `20`),
`manual_rate`, `carry_forward`. See `MealRateSetting::current()` and
`resolveRate()`.

**`claims`** — member disputes / out-of-pocket expenses. `institution_id`,
`student_id`, `kind` (`dispute` | `expense`), `subject`, `amount`,
`entry_date`/`breakfast`/`lunch`/`dinner` (for meal disputes), `title`,
`description`, `status` (`pending` | `approved` | `rejected`), `reviewed_by`,
`reviewed_at`, `review_notes`, `result_deposit_id`, `result_transaction_id`. A
claim only touches money when a manager approves it.

**`activity_logs`** — immutable audit. `user_id`, `user_name`, `user_email`
(denormalised), `institution_id`, `event` (created/updated/deleted/login/…),
`description`, `subject_type`/`subject_id`/`subject_label`, `properties` (JSON
`{ field: { old, new } }`), `ip_address`, `user_agent`. Indexed by subject,
`(institution_id, created_at)`, `(user_id, created_at)` and `event`.

**`email_logs`** — the outbox. `institution_id`, `user_id`, envelope
(`to`,`from`,`cc`,`bcc`,`subject`), `kind`, `mailable`, `status`
(sent/failed/pending), `error`, `body` + `text_body` (full rendered HTML),
`message_id`, `sent_at`. Written by a single auto-discovered `MessageSent`
listener in `app/Listeners` (registered by discovery — **not** re-registered in
`AppServiceProvider`, which previously caused duplicate rows).

**`notifications`** — Laravel database notifications plus `institution_id` for
scoping. Bulletin announcements reuse the same table (one row per recipient).

**`subscription_plans`** — SSA pricing tiers (platform-level). `key`, `name`,
`monthly_price`, `is_free`, `is_trial_default`, `member_limit`, `manager_limit`
(`-1` = unlimited), `features` (JSON), `sort_order`, `is_active`, `is_public`.
Seeded with `free_trial`, `standard`, `enterprise`.

**`landing_enquiries`** — public demo requests. `name`, `email`,
`institution_name`, `institution_type`, `message`, `status`
(new/contacted/approved/rejected), `institution_id` (set once provisioned),
`reviewed_by`, `reviewed_at`, `review_notes`. Platform-level.

**`staff_broadcasts`** — platform announcements. `title`, `body`, `audience`
(institution_admins/admins/members/all), `severity`, `recipients`, `sent_by`.
Platform-level (deliberately **no** tenant trait).

### 4.3 Roles & permissions (RBAC)

`database/seeders/RolesAndPermissionsSeeder.php` defines permissions grouped by
`module` and seeds **four core roles** into a 3-tier hierarchy. The `module`
column is added by `2026_09_15_120000_add_module_to_permissions_table`.

```
Tier 1  Software Super Admin  (GLOBAL)
        ▸ syncPermissions($allPermissions) — every ability
        ▸ the ONLY role holding monitoring.* and institutions.*
        ▸ the ONLY role whose queries may run without a tenant scope
        ▸ Gate::before() also grants it every ability (so a new permission never locks it out)

Tier 2  Institution Admin / Meal Manager  (INSTITUTION-SCOPED)
        ▸ everything operational WITHIN one institution; zero cross-tenant reach
        ▸ cannot hold monitoring.* / institutions.*
        ▸ every query auto-filtered to their institution_id

Tier 3  Member  (PERSONAL)
        ▸ meals.view, transactions.view, claims.view, claims.submit, notifications.view
        ▸ personal dashboard/deposits/meals/balance/claims only
```

Permission modules seeded: `transactions`, `meals`, `students`, `departments`,
`vendors`, `subsidies`, `exports`, `institution`, `users`, `roles`, `audit`,
`appearance`, `institutions`, `monitoring`, `claims`, `notifications`, `emails`,
`currency`, `plans`.

> **Keep three lists in sync.** Permission strings must match (a) the route
> middleware in `routes/web.php`, (b) the `permission` keys in
> `resources/js/Utils/navItems.js`, and (c) the seeder. A typo silently hides a
> nav item or 403s a route.

---

## 5. The maths — `FinanceCalculator`

`app/Support/FinanceCalculator.php` is the **single source of truth for money**.
Controllers call it; nothing else computes financial figures. This is why the
dashboard, reports and mobile API can never disagree.

Two sources of truth:

```
expenses  : transactions where type = 'out'   (Cash Out)
meals     : SUM(breakfast + lunch + dinner) on meal_entries
```

### 5.1 The core identity

```
per-meal rate = total expense ÷ total meals
```

- **Total expense** = `expensesForMonth()` — sums `transactions` `type='out'`,
  **excluding** an out-transaction whose `meal_expense` was later reversed
  (`reversed_at IS NOT NULL`).
- **Total meals** = `mealsForMonth()` — `SUM(breakfast + lunch + dinner)` across
  `meal_entries` for the month. Meals are summed from the **columns**, not counted
  as rows: a member can eat three meals in one row.

Rate mode is honoured by `MealRateSetting::resolveRate($expense, $meals)`:

```php
$calculated = $totalMeals > 0 ? round($totalExpense / $totalMeals, 4) : 0.0;

return match ($this->rate_mode) {
    'manual' => (float) ($this->manual_rate ?? $calculated),   // fixed rate
    'hybrid' => max((float) ($this->manual_rate ?? 0), $calculated), // floor vs ceiling
    default  => $calculated,                                    // 'calculated'
};
```

### 5.2 Monthly snapshot

`monthSnapshot($month, $memberCount = null)` returns every derived figure in one
call (so a controller never re-aggregates):

| Key | Formula |
|---|---|
| `meals`, `expenses`, `deposits`, `subsidies` | the four aggregates |
| `per_meal_rate` | `perMealRate($month)` |
| `meal_cost` | `round(meals × rate, 2)` |
| `subsidy_coverage_pct` | `min(1, subsidies / mealCost) × 100` |
| `member_funded_pct` | `min(1, deposits / mealCost) × 100` |
| `member_shortfall` | `max(0, mealCost − deposits)` |
| `pool_balance` | `deposits + subsidies − expenses` |
| `meals_per_member`, `cost_per_member` | per-head averages |
| `daily_meals`, `daily_cost` | divided by `daysInMonth` |

`depositsForMonth()` sums `type='in'` rows **excluding** reversal cash-ins
(`category = 'Expense Reversal'`), so reversing an expense correctly reduces the
deposit figure too.

### 5.3 Per-member breakdown

`memberBreakdown($month)` returns one row per member, all priced at the month's
rate. It runs **three grouped queries** (not one per member — this is the
deliberate fix for an N+1):

```php
$mealCounts     = MealEntry::…->groupBy('student_id')->get()->keyBy('student_id');
$depositTotals  = Deposit::whereNull('reversed_at')->…->groupBy('student_id')->get()->keyBy('student_id');
$members        = Student::with('department:id,name')->orderBy('name')->get();
```

Per member:

```
cost    = round(meals × rate, 2)
balance = round(deposited − cost, 2)      // negative = member owes the pool
is_due  = balance < 0
subsidy_share = subsidyPool ÷ memberCount   // even per-head split
```

Deposits come from the **`deposits` table** (filtered `reversed_at IS NULL`), not
the ledger — so a reversed deposit drops the balance the moment it is flagged.

### 5.4 Subsidy coverage & the strict balance rule

`Subsidy::APPLY_MODES` — `pool` (common pool), `per_member` (even split),
`credit_behind` (reserve, tapped last). The **strict balance rule**
(`Subsidy::creditAvailableFor($ownDeposits, $mealCost)`) makes reserve funds the
last resort:

```php
$shortfall = max(0, $mealCost - $ownDeposits);   // only the uncovered portion
if ($shortfall <= 0) return 0.0;

$reservePool = static::query()->active()->where('apply_mode', 'credit_behind')->sum('amount');

return round(min($reservePool, $shortfall), 2);  // never more than the shortfall
```

A member's own money is **always** consumed first; reserve subsidy only tops up
the remainder.

### 5.5 The forecast — `forecast($lookback = 3)`

Deliberately simple and explainable (not a black box). `FORECAST_LOOKBACK_MONTHS = 3`.

1. **Collect** the last N months via `monthSnapshot()`.
2. **Weight** them on a linear ramp — `weightedAverage()`: oldest = weight 1,
   newest = weight N (newest counts 3× on a 3-point series). A growing mess is
   projected on its recent trend.
3. **Growth rate** = `growthRate()`: `((last − first) / first) / (n − 1)`,
   **clamped to ±35%** so one unusual month cannot blow the projection up. A zero
   baseline returns 0 (flat).
4. **Project** next month: `weightedMeals × (1 + mealGrowth)`,
   `weightedExpense × (1 + expenseGrowth)`; the projected rate is
   `projectedExpense / projectedMeals` (falling back to the current rate if meals
   are zero).
5. **Subsidy required** = `projectedCost × target_subsidy_ratio`
   (`target_subsidy_ratio` is a `%`, default `20` → the "80/20 rule").

It also returns a 3-month `horizon` and an `assumptions` block (method, ratios,
`has_history`). Everything is `round()`ed and clamped on purpose: a forecast that
produces `−4,000,000` because of a zero-baseline month is worse than no forecast.

### 5.6 Month-scoping helpers

```php
FinanceCalculator::monthBounds('2026-09');   // [firstDay, lastDay] Carbon
FinanceCalculator::resolveMonth($input);     // validates /^\d{4}-\d{2}$/ else current month
```

Reports, deposits, expenses, subsidies and member figures all filter by
`YYYY-MM`. There are **no free date ranges** in the user-facing UI.

---

## 6. The theme engine

The theme is a set of tokens persisted in two places and applied as CSS custom
properties. The pieces:

- `resources/js/Components/ThemeProvider.jsx` — resolves + applies the theme.
- `resources/js/Components/ThemeToggle.jsx` — the header light/dark switch.
- `resources/css/app.css` — the token defaults, the `.dark` block and the
  utility remap.
- `tailwind.config.js` — adds a `3xl` (1920px) breakpoint for large screens.
- `app.jsx` — applies the theme **before React mounts**.

### 6.1 Three-tier precedence (DB → localStorage → institution)

`resolveInitialTheme(props)` is the single precedence resolver, shared by the
pre-mount paint and the provider so they can never drift:

```js
function resolveTheme(userTheme, institutionTheme) {
    if (userTheme && Object.keys(userTheme).length > 0) return { ...DEFAULT_THEME, ...userTheme }; // 1. DB
    const local = readLocalTheme();
    if (local) return { ...DEFAULT_THEME, ...local };                                             // 2. localStorage
    if (institutionTheme) return { ...DEFAULT_THEME, ...institutionTheme };                       // 3. institution
    return DEFAULT_THEME;                                                                          // 4. default
}

export function resolveInitialTheme(props = {}) {
    return resolveTheme(props?.auth?.user?.theme, props?.institution?.theme);
}
```

1. **Database** (`users.theme`, shared as `auth.user.theme`) — follows the user to
   *any* device. **Wins** when signed in.
2. **localStorage** (`tt.theme`, keyed to the browser) — applies on *this* PC even
   before login / for the next person.
3. **Institution** (`institutions.theme`) — a sensible workspace default.
4. Platform default (`DEFAULT_THEME`).

`applyThemeTokens($theme, { persist })` writes the tokens; `persist` is true when
the **account** supplied the theme (so the browser copy tracks the signed-in
user), false for a pure localStorage/institution fallback.

### 6.2 CSS custom properties

`applyThemeTokens` sets these on `document.documentElement`:

| Group | Tokens |
|---|---|
| Accent | `--accent`, `--accent-soft`, `--accent-ring`, and the legacy aliases `--primary-color`, `--primary-soft` |
| Surfaces | `--bg-color`, `--surface`, `--surface-soft`, `--surface-muted`, `--border-color` |
| Text | `--text-primary`, `--text-secondary`, `--text-muted` |
| Shape/density | `--radius`, `--density`, `--font-scale`, `--font-scale-min`, `--font-scale-max` |
| Type | `--font-family` |

It also toggles the global class: `root.classList.toggle('dark', isDark)` /
`toggle('light', !isDark)` and sets `data-theme-mode` + `style.colorScheme`, then
loads the chosen Google font once (`loadGoogleFont`). Components then style
themselves via `bg-[var(--accent)]` or the semantic helper classes (`.surface`,
`.card-theme`, `.text-muted`, `.border-theme`, `.focus-theme`) defined in
`app.css`, so changing the accent repaints the app with **no code change**.

### 6.3 The `.dark` utility remap

The app was authored with literal Tailwind colours (`bg-white`, `text-slate-900`,
`border-slate-200`, …). Rather than rewrite every component, `app.css` **remaps**
the neutral utilities inside `.dark`:

```css
.dark .bg-white, .dark .bg-white\/90, …        { background-color: var(--surface) !important; }
.dark .text-slate-900, .dark .text-slate-800, … { color: var(--text-primary) !important; }
.dark .text-slate-600, .dark .text-slate-500, … { color: var(--text-secondary) !important; }
.dark .border-slate-200, …                      { border-color: var(--border-color) !important; }
```

Specificity: `.dark .x` is one class more than `.x`, so these win without
`!important` in most cases — except paired utilities (`bg-white hover:bg-slate-50`),
which have explicit `hover:` rules. The completeness layer then closes the gaps:
opacity variants, ring/divide variants, status/accent tint chips
(`bg-indigo-50 text-indigo-700` → translucent tint + lifted text), placeholders,
`<option>` popups, dividers, form controls, table headers, checkboxes and
WebKit scrollbars. **Brand/status colours are left alone** — they are meaningful
signals and read fine on both surfaces.

Keyframes `page-in`, `fade-in`, `rise` (and the landing/auth `wa-*` vocabulary)
live here too; all are disabled under `prefers-reduced-motion`.

### 6.4 Density & fluid (clamp) typography

Tailwind's `text-*` utilities are rem-based, so scaling the **root** font-size
scales all type proportionally. `ThemeProvider` writes `--font-scale` (the density
base) plus `--font-scale-min/max`; `app.css` then clamps:

```css
html {
  font-size: clamp(
    var(--font-scale-min, 14px),
    calc(var(--font-scale, 15px) + 0.15vw),
    var(--font-scale-max, 16px)
  );
}
```

`DENSITY_RANGE` in `ThemeProvider.jsx` maps the density token to the bounds:

| Density | Base (`DENSITY_SCALE`) | Min | Max |
|---|---|---|---|
| `compact` | 14px | 13px | 15px |
| `comfortable` | 15px | 14px | 16px |
| `spacious` | 16px | 15px | 18px |

So one density choice scales the whole app's type from phone to TV with no
per-component breakpoint.

### 6.5 ThemeProvider / ThemeToggle and the pre-mount paint

`app.jsx` paints the theme **before React mounts** to avoid a flash of the
default accent, and re-applies on every successful Inertia visit:

```js
setup({ el, App, props }) {
    resolveAndApply(props?.initialPage?.props);          // 1. pre-mount paint
    router.on('success', (event) => {
        resolveAndApply(event.detail.page.props);         // 2. repaint on every visit
    });
    …
}
```

`resolveAndApply` calls `applyThemeTokens(resolveInitialTheme(props), { persist })`;
`persist` is true only when the **account** supplied a theme. `ThemeProvider`
wraps the app in `AuthenticatedLayout`, `GuestLayout` and (transitively) every
page, and exposes `useTheme()` → `{ theme, applyPreview, resetPreview }` to the
`Settings/ThemeCustomizer` page.

### 6.6 How the theme reaches guest / landing pages

`GuestLayout` explicitly wraps its children in `ThemeProvider`. Before this, only
`AuthenticatedLayout` mounted it, so login/register/reset screens got the CSS
variables but **not** the live React theme context — `useTheme()` fell back to
defaults there. The landing page (`Welcome.jsx`) receives the global variables
from the pre-mount paint and shares the same `wa-*` entrance animations, so
landing and auth look like one continuous product.

---

## 7. The mobile API layer

`routes/api.php` + `app/Http/Controllers/Api/*`. Same models and services as the
web layer — only the response format differs.

### 7.1 Auth model — Sanctum bearer tokens

The mobile client does **not** use cookies or sessions. `AuthController::login()`
exchanges `email + password` for a token and returns:

```json
{ "data": { "token": "<plain-text>", "token_type": "Bearer", "user": { …UserResource… } } }
```

The client stores the token and sends `Authorization: Bearer <token>` on every
call. `auth:sanctum` validates it; `$request->user()` is then the token's owner.
`logout()` revokes the **calling** token only (`currentAccessToken()->delete()`),
so signing out on one device does not sign the user out everywhere.

Login uses the **same message** for "no such user" and "wrong password" so the
endpoint cannot be used to enumerate emails. `register()` always assigns the
`Member` role (no `role` field is accepted).

### 7.2 Token expiration (30 days)

`config/sanctum.php` was published specifically to pin `expiration`. The framework
default was `null` (tokens never expire); here it is:

```php
'expiration' => env('SANCTUM_EXPIRATION', 43200),   // 30 days
```

`SANCTUM_EXPIRATION` overrides it per environment (`null` opts back into
non-expiring tokens — not recommended). `guard` is `['web']`; the mobile flow
only uses `guard` and `expiration`.

### 7.3 Throttling

Defined as named limiters in `AppServiceProvider::boot()`:

```php
RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)
    ->by($r->user()?->id ?: $r->ip()));                 // baseline, keyed per user or IP

RateLimiter::for('api-login', fn (Request $r) => Limit::perMinute(5)
    ->by(Str::lower((string) $r->input('email')) . '|' . $r->ip()));  // per email + IP
```

- `throttle:api` is appended to the whole API group in `bootstrap/app.php` (the
  framework's default API group is **unthrottled**).
- `throttle:api-login` is added on `POST /auth/register` and `POST /auth/login`
  only, so credential guessing is slowed **without** letting one attacker lock out
  a different email from the same address.

### 7.4 Tenant-scoped validation — `ApiFormRequest`

Every API Form Request extends `App\Http\Requests\Api\ApiFormRequest`. Its
`tenantExists()` closes a real cross-tenant hole. The original rules were plain
`exists:students,id`, which checks the **whole table** — a caller in institution A
could pass a `student_id` from institution B.

```php
protected function tenantExists(string $table, string $column = 'id'): Exists
{
    $rule = Rule::exists($table, $column);

    $tenantId = $this->tenantId();                 // TenantManager::resolveTenantId()
    if ($tenantId !== null) {
        $rule->where('institution_id', $tenantId);  // scope to the active tenant
    }

    return $rule;                                  // global (SSA) context stays table-wide
}
```

A foreign id is now a clean **422** at validation time, before any write. Requests:
`StoreDepositRequest` (`student_id` scoped), `StoreMemberRequest`
(`department_id`, `manager_id` scoped), `UpdateMemberRequest`, `StoreMealDayRequest`
(`entries.*.student_id` scoped — the original controller never re-checked the
student), `StoreSubsidyRequest`.

### 7.5 API Resources

Serialization lives in `app/Http/Resources/*` so each shape is defined once and
cannot drift between endpoints:

| Resource | Used by | Notes |
|---|---|---|
| `UserResource` | every auth endpoint | roles, permissions, `is_super_admin`, `avatar_url`; used via `resolve()` so it nests under `data` |
| `DepositResource` | `DepositApiController::index` | money as a `float`; `recorder`/`student` eager-loaded |
| `MealEntryResource` | `MealApiController` | `total` computed server-side |
| `SubsidyResource` | `SubsidyApiController` | `scope` = department → member → "Whole institution" |

Member lists in `MemberApiController` map rows inline (joining the model to a
precomputed `memberBreakdown` keyed by id) — that payload is a join, not a plain
model serialization.

### 7.6 Endpoints

| Method | Path | Handler |
|---|---|---|
| POST | `/api/auth/register` | `AuthController@register` (throttle:api-login) |
| POST | `/api/auth/login` | `AuthController@login` (throttle:api-login) |
| GET | `/api/meta` | `AuthController@meta` — currency, terminology, enum lists |
| GET | `/api/auth/me` | `AuthController@me` |
| POST | `/api/auth/logout` | `AuthController@logout` |
| POST | `/api/auth/devices` | `AuthController@registerDevice` |
| PATCH | `/api/auth/profile` | `AuthController@updateProfile` |
| GET | `/api/dashboard` | `DashboardApiController@index` |
| GET | `/api/members`, `/members/{member}` | `MemberApiController` |
| POST/PATCH/DELETE | `/api/members…` | `MemberApiController` |
| GET/POST | `/api/deposits` (+ `/deposits/export`) | `DepositApiController` |
| GET/POST | `/api/meals`, `/meals/day` | `MealApiController` |
| GET/POST | `/api/subsidies` (+ `/subsidies/sources`) | `SubsidyApiController` |
| GET | `/api/reports/meal`, `/reports/analytics`, `/reports/forecast`, `/reports/per-meal-rate` | `ReportApiController` |

### 7.7 Conventions

- Collections are wrapped in `{ data, meta }`; `meta` carries month, totals,
  enum lists (from `Subsidy::APPLY_MODES`, `Deposit::KINDS`, …) and pagination.
- Money is a JSON **number**; `/meta` serves the currency config so the client
  formats locally (no symbol hard-coding).
- Dates are ISO-8601 (`created_at->toIso8601String()`); month filters use
  `YYYY-MM`.
- Page sizes are capped (`min((int) $request->query('per_page', 25), 100)`).
- Full reference: [`docs/API.md`](./API.md).

---

## 8. Frontend architecture (Inertia + React)

### 8.1 Inertia bootstrap & the page-resolution contract

`resources/js/app.jsx` boots Inertia and resolves pages by **name** using a Vite
glob:

```js
resolve: (name) =>
    resolvePageComponent(
        `./Pages/${name}.jsx`,
        import.meta.glob('./Pages/**/*.jsx'),
    ),
```

This is the **hard contract**: the string passed to `Inertia::render('Meals/Students/Index')`
must correspond to a file at `resources/js/Pages/Meals/Students/Index.jsx`. There
are ~54 `Inertia::render(...)` call sites across `app/Http/Controllers` (plus one
inline in `routes/web.php` for `Settings/RoleManager`), resolving into the 57 JSX
files under `resources/js/Pages`. A typo on either side is a blank white page with
a console error — grep for the exact page string before renaming anything.

`app.jsx` also:
- imports `../css/app.css` and `./bootstrap`;
- sets the browser title (`${title} - ${appName}`);
- wraps everything in `FeedbackProvider` (flash + confirmations, working on guest
  pages too) and renders one `GlobalLoadingIndicator`;
- configures Inertia's progress bar to `var(--accent, #4f46e5)`.

### 8.2 The `@/` alias and Ziggy

`jsconfig.json` maps `@/*` → `resources/js/*` and `ziggy-js` → the vendored Ziggy.
So `import Sidebar from '@/Components/Sidebar'` and `route('meals.students.index')`
both work. The Ziggy route list is injected via the Inertia share.

### 8.3 Layouts

| Layout | File | Wraps |
|---|---|---|
| `AuthenticatedLayout` | `resources/js/Layouts/AuthenticatedLayout.jsx` | `ThemeProvider`, `Sidebar` (docked on `lg+`, off-canvas drawer below), sticky mobile top bar, `ThemeToggle` + `NotificationBell`, the SSA switched-view banner, animated `<main>` |
| `GuestLayout` | `GuestLayout.jsx` | `ThemeProvider`, platform branding logo/name, ambient glow, the auth card |
| `MealsLayout` | `MealsLayout.jsx` | `AuthenticatedLayout` + permission-filtered, term-aware module tabs |
| `SettingsLayout` | `SettingsLayout.jsx` | a thin wrapper over `AuthenticatedLayout` (nested-layout pattern) |

`AuthenticatedLayout` adapts across breakpoints and uses the theme tokens for its
own shell (`backgroundColor: 'var(--bg-color)'`), so a dark-mode flip recolours
the shell and every page in one paint. It widens at `2xl`/`3xl` for TV-sized
displays while capping line length.

### 8.4 Shared props & client helpers

Because `HandleInertiaRequests` shares `auth`, `institution`, `terms`, `currency`,
`theme`, `tenant`, `notifications` and `flash` on every response, components read
them from `usePage().props` and never re-fetch. Client utilities:

- `resources/js/Utils/can.js` (`useCan`) → `can('students.manage')`, drives the
  nav + in-page gates.
- `resources/js/Utils/useTerminology.js` (`useTerminology`) → `t('members')`
  resolves to "Students" / "Employees" / "Boarders" from `institution.terms`.
- `resources/js/Utils/useMoney.js` → formats using the shared `currency` config.
- `resources/js/Utils/navItems.js` → the single source of truth for the sidebar;
  `buildVisibleNav()` prunes by permission, role and the `switched` flag.

`GlobalLoadingIndicator` listens to Inertia router events, waits 260 ms before
appearing (so fast navigations don't flash) and holds 180 ms once shown (so it
never blinks).

---

## 9. Testing & verification

### 9.1 Layout — a Role/Module tree, mirrored on both suites

`tests/Feature` and `tests/Browser` are organised the same way: **by role, then by
module, then by behaviour.** The four roles are `Guest`, `Member`, `MealManager`,
`InstituteAdmin`, `SoftwareSuperAdmin`.

```
tests/
├── TestCase.php                      # base PHPUnit case (Laravel Testing\TestCase)
├── DuskTestCase.php                  # base Dusk case (uses DuskDatabase)
├── Unit/
│   └── Support/MoneyTest.php
├── Feature/                          # PHPUnit — HTTP + service tests
│   ├── Api/                          # mobile API
│   │   ├── ApiTestCase.php           # fixtures (makeInstitution/makeStaff/makeMember/makeDepartment)
│   │   ├── Auth/            ApiAuthenticationTest, ApiLoginThrottleTest
│   │   ├── Deposits/        DepositApiTest
│   │   ├── Members/         MemberApiTest
│   │   └── Tenancy/         ApiTenantIsolationTest
│   ├── Guest/           Auth/*, Home/HomePageTest
│   ├── Member/          Auth/*, Profile/ProfileTest
│   ├── InstituteAdmin/  Departments/DepartmentGuardTest, InviteCode/InviteCodeTest
│   └── SoftwareSuperAdmin/ Auth/SuperAdminPasswordGuardTest
└── Browser/                          # Dusk — {Role}/{Module}/{Feature|Unit}/
    ├── {Role}/{Module}/Feature/*Test.php   # e.g. InstituteAdmin/MealEntries/Feature/MealEntriesTest.php
    │                                       #      SoftwareSuperAdmin/InstitutionRegistry/Feature/ListTest.php
    ├── Guest/Welcome/Feature/WelcomeTest.php
    ├── Pages/{Page.php, HomePage.php}
    └── Support/{DuskDatabase.php, DuskSupport.php}
```

Note the `*TestCase.php` naming: the shared bases (`tests/TestCase.php`,
`tests/Feature/Api/ApiTestCase.php`, `tests/DuskTestCase.php`) do **not** end in
`Test.php`, so PHPUnit never tries to run them as test classes.

### 9.2 `phpunit.xml` (Unit + Feature)

- Suites: `Unit` → `tests/Unit`, `Feature` → `tests/Feature`.
- `bootstrap="vendor/autoload.php"`, coverage source = `app`.
- Test env: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
  `CACHE_STORE=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`,
  `SESSION_DRIVER=array`, `BCRYPT_ROUNDS=4`.

### 9.3 `phpunit.dusk.xml` (Browser)

- One suite: `Browser Test Suite` → `tests/Browser` (suffix `Test.php`).
- Raises `memory_limit` to `2G` — Dusk boots a full app + browser driver per test
  class and a large hierarchical suite can exceed PHP's default 128M.
- Uses `.env.dusk.local`.

`DuskTestCase` uses `Tests\Browser\Support\DuskDatabase`, which **commits** the
schema and fixtures (rather than wrapping each test in a rollback transaction), so
the separate `artisan serve` process the browser talks to can actually see the
data. It disables only the CSRF middleware (so real `actingAs()->post()` calls
don't 419) while every other middleware (auth, `role:`, `permission:`) stays live;
clears cookies between tests (Dusk reuses one browser) and truncates the domain
tables in `tearDown`.

### 9.4 How to run

```bash
php artisan test                       # PHPUnit Unit + Feature
php artisan test --testsuite=Feature   # one suite
php artisan test tests/Feature/Api     # one directory

php artisan dusk                       # the full Dusk browser suite (needs chromedriver)
php artisan dusk tests/Browser/InstituteAdmin/Members/Feature/CreateTest.php

npm run build                          # front end must compile
php artisan migrate --force            # schema must apply cleanly
php artisan tinker                     # poke at models by hand
```

**Before shipping a change:** `npm run build`, run migrations, then load the
affected pages in a browser and confirm **zero console errors and zero failed
requests**. Reading the code is not verification; looking at the page is.

---

## 10. Directory map, adding a feature & gotchas

### 10.1 Directory map

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/                 JSON API (mobile): Auth, Dashboard, Member,
│   │   │                        Deposit, Meal, Subsidy, Report
│   │   ├── Auth/                Breeze-style session auth
│   │   └── Meals/               Meal module: Student, Department, Deposit,
│   │                            MealEntry, MealExpense, Subsidy, MealReport
│   ├── Middleware/
│   │   ├── ResolveTenant.php             validate + pin the tenant
│   │   ├── EnsurePasswordIsChanged.php   forced password change
│   │   └── HandleInertiaRequests.php     share auth/institution/theme/… props
│   ├── Requests/Api/            ApiFormRequest + Store*/Update* requests
│   └── Resources/               UserResource, DepositResource, MealEntryResource,
│                                SubsidyResource
├── Models/
│   ├── Concerns/BelongsToInstitution.php   the tenant scope trait
│   └── Institution, User, Student, Department, Deposit, MealEntry,
│       MealExpense, MealRateSetting, Subsidy, SubsidySource, Vendor,
│       Transaction, Claim, ActivityLog, EmailLog, SubscriptionPlan,
│       LandingEnquiry, StaffBroadcast, Notification, …
├── Providers/AppServiceProvider.php   rate limiters, model observers, Gate::before
├── Services / Support/
│   ├── TenantManager.php  ★ the tenant resolver
│   ├── FinanceCalculator.php  ★ all money maths
│   ├── AuditLogger.php    writes audit rows
│   ├── RecordActivity.php model observer → AuditLogger
│   ├── ReportExporter.php Excel (SpreadsheetML) + PDF (print HTML)
│   ├── InstitutionProvisioner.php  the one provisioning path
│   ├── PlatformBranding.php  master product name / labels
│   └── Money.php          server-side currency formatting
resources/
├── css/app.css               theme tokens, .dark remap, keyframes
├── js/
│   ├── app.jsx               Inertia bootstrap + pre-mount theme paint
│   ├── Components/           Sidebar, Modal, Field, Icon, ThemeProvider,
│   │                         ThemeToggle, NotificationBell,
│   │                         GlobalLoadingIndicator, Feedback/*
│   ├── Layouts/              Authenticated, Guest, Meals, Settings
│   ├── Pages/                one component per Inertia page (≈57 files)
│   └── Utils/                can.js, useTerminology.js, useMoney.js, navItems.js
├── views/app.blade.php       the Inertia root template (@vite, @inertia)
bootstrap/app.php             middleware stack + aliases + routing
routes/
├── web.php                   Inertia routes + permission/role middleware
├── api.php                   mobile API (Sanctum)
├── auth.php                  Breeze auth routes
└── console.php               scheduled commands
config/
├── sanctum.php               token expiration (30 days) + guards
├── permission.php            Spatie tables
└── platform.php              SSA branding/credential guardrails
database/
├── migrations/               schema (see Section 4)
└── seeders/                  RolesAndPermissionsSeeder, demo data
tests/                        Unit + Feature + Browser (Dusk) — see Section 9
docs/                         API.md, USER_MANUAL.md, SOFTWARE_ARCHITECTURE.md, DUSK_TESTING.md
```

`★` = the two files a new developer should read first.

### 10.2 How to add a feature — a worked example

Say you want a **"meal quality score"** per day.

1. **Migration** — add `quality` to `meal_entries` (`database/migrations/…`).
   Add `institution_id` if the new table is tenant-owned.
2. **Model** — add `quality` to `$fillable`; add `use BelongsToInstitution` if the
   table is per-institution.
3. **Logic** — if it is a computed figure, add a method to `FinanceCalculator`
   (never inline a total in a controller); otherwise read the column.
4. **Controller** — return it in the existing `Inertia::render(…, $props)` call.
5. **UI** — render it in the page component, e.g. `Pages/Meals/Entries/Index.jsx`.
6. **API** — add it to the relevant `app/Http/Controllers/Api/*` payload (and a
   `Resource` if the row shape is reused).
7. **Tests** — add a Feature test under the matching role/module folder and, for
   UI-visible behaviour, a Dusk test under `tests/Browser/…`.
8. **Docs** — note it in `API.md`, `USER_MANUAL.md` and, if architectural, here.

That order matters: **schema → model → logic → controller → UI → API → tests →
docs.**

If the feature introduces a new permission, add it to
`RolesAndPermissionsSeeder` under its `module`, gate the route with
`->middleware('permission:…')`, add the nav entry to `navItems.js`, and remember
`Gate::before` gives the Software Super Admin every ability automatically.

### 10.3 Gotchas learned the hard way

- **Only one instance of a model's money maths.** Duplicated maths is how the
  dashboard and reports drifted apart in an earlier version. Add to
  `FinanceCalculator`; call it from everywhere.
- **The tenant scope is a default, not an opt-in.** If you ever *need* to see
  across tenants, use `TenantManager::runGlobally()` (with a `finally` restore) and
  `Model::withoutTenantScope()` — never a bare `where('institution_id', …)` that
  some other query path will bypass.
- **New tenant tables need the trait *and* the column.** Adding `institution_id`
  without `use BelongsToInstitution` (or vice-versa) leaves a table unscoped.
- **`whereDate`, not `where`.** A `date` column stores `2026-09-16 00:00:00`;
  comparing it to `'2026-09-16'` with `where()` never matches.
- **Ambiguous columns on a join.** Once you `join('transactions')`, qualify every
  column — both tables have `created_at`, and SQLite rejects the ambiguous
  reference outright.
- **Include `payment_method` only when set.** `transactions.payment_method` is NOT
  NULL with a DB default of `'Cash'`; passing an explicit `null` breaks the
  constraint and 500s the request.
- **Scope `exists` rules to the tenant.** Use `ApiFormRequest::tenantExists()`;
  a plain `exists:students,id` checks every institution's rows.
- **Sanitise the terminology map.** Blank overrides must be dropped so the type
  preset shows through again.
- **Signatures bind the host.** `URL::temporarySignedRoute` signs the absolute
  URL, so `APP_URL` must match the host actually served or emailed links fail.
- **`forceFormData` for uploads.** Any request carrying a file needs it, or the
  file is silently dropped.
- **Don't re-register the `MessageSent` listener.** It is auto-discovered from
  `app/Listeners`; registering it again in `AppServiceProvider` made it fire
  twice and wrote duplicate `email_logs` rows.
- **Theme changes must go through the tokens.** Style components with
  `var(--accent)` / `var(--surface)` / `.card-theme`, not literal Tailwind
  colours, or dark mode (the `.dark` remap) will miss them.
- **The page-name string is a contract.** `Inertia::render('A/B')` ↔
  `resources/js/Pages/A/B.jsx`. Renaming one side without the other is a blank
  page.
