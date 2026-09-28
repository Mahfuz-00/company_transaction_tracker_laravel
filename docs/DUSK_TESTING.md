# Laravel Dusk Test Suite — Process & Execution Documentation

> **Project:** `transaction-tracker` (Laravel 12 + Inertia.js + React monolith)
> **Working directory:** `F:\Mahfuz\Mobile App\transaction-tracker`
> **Scope:** A role-based, hierarchical (Dusk) browser suite for ALL five audiences — Software Super Admin, Institute Admin, Meal Manager, Member, Guest — plus the Unit/Feature suites.

Everything in this document was derived from the **actual code** in this repository (test classes, support traits, `DuskTestCase`, `phpunit.dusk.xml`, `.env.dusk.local`, `phpunit.xml` and the `dusk` CI job). No route, column, selector or flash message was invented.

---

## 1. Structure — Role / Module / Test-Type hierarchy

Tests are organised by **ROLE**, then **MODULE**, then **TEST TYPE**, with one focused leaf file per module (or per action within a module).

```
tests/
├── DuskTestCase.php                       ← base test case (uses DuskDatabase)
├── Browser/
│   ├── Support/
│   │   ├── DuskSupport.php                ← shared fixtures + step() logging
│   │   └── DuskDatabase.php               ← committed, server-visible Dusk DB
│   ├── SoftwareSuperAdmin/
│   │   ├── Login/Feature/LoginTest.php
│   │   ├── Dashboard/Feature/DashboardTest.php
│   │   ├── LandingEnquiries/Feature/{List,Approve,MarkContacted,Reject}Test.php
│   │   ├── InstitutionRegistry/Feature/{List,Create,Toggle,Switch}Test.php
│   │   └── {Monitoring,Analytics,Broadcasts,GlobalAudit,Plans,Trials,
│   │        UserManager}/Feature/*Test.php
│   ├── InstituteAdmin/
│   │   ├── Login/Feature/LoginTest.php
│   │   ├── Dashboard/Feature/DashboardTest.php
│   │   ├── TrialRequest/Feature/TrialRequestTest.php   ← guest request → SSA approval
│   │   ├── Members/Feature/{List,Create,Edit,Delete}Test.php
│   │   └── {Currency,Theme,Departments,Deposits,MealEntries,Expenses,Vendors,
│   │        Reports,Subsidies,ClaimReview,UserManager,Institution,SubsidySources,
│   │        ActivityLog,EmailLog,InviteCode,Broadcasts}/Feature/*Test.php
│   ├── MealManager/
│   │   ├── Login/  Dashboard/            ← each with Feature/<Name>Test.php
│   │   └── {Members,Deposits,MealEntries,Expenses,Vendors,Reports,
│   │        Subsidies,ClaimReview,Currency,Theme}/Feature/*Test.php
│   ├── Member/
│   │   ├── Login/  Dashboard/
│   │   └── {Meals,Deposits,Analytics,ThemeCustomizer,Claims,Profile}/Feature/*Test.php
│   ├── Guest/
│   │   └── Welcome/Feature/WelcomeTest.php
│   └── Pages/                             ← unchanged Dusk scaffolding (Page.php, HomePage.php)
└── (DuskTestCase configures /dev/shm-free, headless Chrome — see §4)
```

The third level is the **test type**. Every current Dusk test drives a real browser (or the
full HTTP kernel), so they all live under `Feature/`; `Unit/` exists in the grammar for
future non-browser assertions. Note there is **no `Modules/` wrapper** — an action such as
`Create` or `List` is a *file* inside `Feature/`, not a folder.

`phpunit.dusk.xml` discovers `./tests/Browser` **recursively** by the `Test.php` suffix, so
any depth of nesting is picked up with no config change.

Every leaf `*Test.php` extends `Tests\DuskTestCase` and pulls in the `Tests\Browser\Support\DuskSupport` trait:

```php
namespace Tests\Browser\InstituteAdmin\Modules\Members\Create;

use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

class CreateTest extends DuskTestCase
{
    use DuskSupport;
    // ...
}
```

Every authenticated-role test follows the same sequence: **Login → land on the dedicated dashboard → then exercise each module one by one.** Guest tests only the public landing page.

### What each role's suite proves
| Role | Highlights |
|------|-----------|
| **Software Super Admin** | Global login → `/platform`; landing-enquiry **approve → 7-day trial provisioning**; institution registry create/switch/toggle; monitoring subscription update; plans; broadcasts; global audit; cross-tenant isolation. |
| **Institute Admin** | Scoped `/dashboard`; roster isolated by `institution_id`; member CRUD; currency config persists; departments/deposits/entries/expenses/vendors/subsidies/claims; invite code; **cross-institution user management refused**. |
| **Meal Manager** | **Assignment-scoped** roster/deposits/entries (only `students.manager_id = me`); records expenses/vendors; claim review restricted to assigned members; view-only currency/subsidies. |
| **Member** | Personal `/my/dashboard`, own meals/deposits/analytics; raises claims; **dark-mode toggle persists to `users.theme`**; profile + password change; blocked from admin consoles. |
| **Guest** | Landing page renders; demo form writes a real `LandingEnquiry`; public pricing grid reflects SSA-configured plans; a signed-in user is redirected away from the landing page. |

### Console progress logging
Every test step emits a labelled dump line so a developer can follow the run:

```php
$this->step('InstituteAdmin', 'Members', 'open Add Member modal', __LINE__);
//  ==== STEP: [InstituteAdmin] → [Members] → [open Add Member modal] | Line ~31 ====
```

This is centralised in `DuskSupport::step($role, $module, $action, $line)`. Pass `__LINE__` so the output points at the exact line in the test.

### Test count
`php artisan dusk` reports **242 tests** across **61 test classes** (Software Super Admin 17 classes, Institute Admin 23, Meal Manager 12, Member 8, Guest 1), plus the Onboarding, TopBar and Intelligence module suites. The whole suite runs in roughly 3 minutes.

---

## 2. Shared test support — the real helpers

There is **no** `BuildsTenantFixtures` trait in this repository. Fixtures and step logging live in **`Tests\Browser\Support\DuskSupport`**, and the committed test-database handling lives in **`Tests\Browser\Support\DuskDatabase`** (used by `Tests\DuskTestCase`).

### 2.1 `Tests\Browser\Support\DuskSupport` (trait)

| Helper | What it does |
|--------|--------------|
| `seedRbac()` | Seeds `RolesAndPermissionsSeeder` (run **before** any user is created — the Spatie `role:`/`permission:` middleware and `User::isSuperAdmin()` guards need the rows). |
| `step(string $role, string $module, string $action, int $line = 0)` | Emits the labelled `==== STEP: ... ====` console line. |
| `waitForTextCaseInsensitive(Browser $browser, string $text, int $seconds = 20)` | `waitForText($text, $seconds, true)` — immune to Tailwind `uppercase` (WebDriver `getText()` returns visually-transformed text). |
| `httpAs($user)` | An authenticated HTTP client for server-side assertions; disables **only** the CSRF middleware (`ValidateCsrfToken`/`VerifyCsrfToken`) and sets a same-origin `Referer`, so it runs through the full kernel without tripping a `419`. |
| `makeInstitution(array $attributes = [])` | `Institution::create(...)` with a unique name/slug, `onboarding_mode=subscription`. |
| `makeTenantUser(Institution $institution, string $role, array $attributes = [])` | Tenant-bound user (Institution Admin / Meal Manager / Member) + `assignRole($role)`. |
| `makeInstitutionAdmin(...)` | Convenience wrapper (`Institution Admin`). |
| `makeMealManager(...)` | Convenience wrapper (`Meal Manager`). |
| `makeMember(Institution $institution, array $attributes = [], bool $withMemberRecord = true)` | A `Member` (also creates the linked `students` row by default). |
| `makeSuperAdmin(array $attributes = [])` | The **global** SSA — `institution_id = NULL`, holds only `Software Super Admin`. |
| `makeStudent(Institution $institution, array $attributes = [])` | A roster `Student` (optionally overseen by a `manager_id`). |
| `roleExists(string $name)` | Sanity helper — does the named Spatie role row exist? |

### 2.2 `Tests\Browser\Support\DuskDatabase` (trait, used by `DuskTestCase`)

Dusk drives a **real browser against a separate `php artisan serve` process** that opens its **own** database connection. The stock `RefreshDatabase` transaction is never committed, so fixtures are invisible to that server process (a real form login then hangs on `waitForLocation()`). `DuskDatabase` fixes this **test-side only**.

| Method | What it does |
|--------|--------------|
| `migrateDuskDatabase()` | Once-per-process: points the app at a **file-backed SQLite DB** (`database/dusk.sqlite`), forces WAL + a 5s busy timeout, runs `migrate:fresh --force` **committed** so the server sees the schema. |
| `truncateDuskTables()` | Between tests, deletes rows from the domain tables (committed) so tests stay independent without a rollback transaction. |
| `duskSchemaExists()` | Has the shared schema been migrated this session (`users` + `institutions` present)? |
| `relaxInstitutionTimezoneConstraint()` | Test-only schema reconciliation: drops `NOT NULL` on `institutions.timezone` so the app's explicit `timezone => null` provisioning writes don't 500 the flow. Affects only the throwaway Dusk DB. |

> **Roles are seeded per test.** Because the tables are truncated between tests, each test must call `$this->seedRbac()` before creating users — that is what keeps the Spatie `role:` / `permission:` middleware and the app's role guards working.

---

## 3. Verified source facts used by the tests

These were read directly from the codebase — they are the "contract" the tests assert against.

### 3.1 Roles & access tiers (`database/seeders/RolesAndPermissionsSeeder.php`)
Exactly four core roles (no legacy aliases):
- `Software Super Admin` — the **global** role; holds *everything*, including `monitoring.*` / `institutions.*`.
- `Institution Admin` — operational, **scoped** to one institution; no `monitoring.*` / `institutions.*`.
- `Meal Manager` — runs the mess; sees subsidies but does not manage them.
- `Member` — `meals.view`, `transactions.view`, `claims.view`, `claims.submit`, `notifications.view` only.

Global vs tenant is decided by `User::isSuperAdmin()` (role-based) **and** `users.institution_id` being `null`.

### 3.2 Multi-tenant columns (migrations, verified)
- `users`: `institution_id` (nullable FK), `invitation_pending`, `must_change_password`, `password_changed_at`, `setup_completed_at`, `theme` (json), `designation`, `status`, `phone`, `last_login_at`, `avatar_path`.
- `institutions`: `name`, `slug`, `type`, `currency_code`, `currency_settings` (json), `timezone`, `terminology` (json), `settings` (json), `subsidy_mode`, `subscription_plan`, `subscription_status`, `subscription_amount`, `subscription_started_at`, `subscription_renews_at`, `member_limit`, `health_notes`, `last_reviewed_at`, `onboarding_mode`, `trial_started_at`, `trial_ends_at`, `trial_reminder_sent_at`, `converted_at`, `is_active`, `invite_code`.
- `students`: `institution_id`, `user_id`, `manager_id`, `name`, `roll`, `department_id`, `join_date`, `status`.
- `member_invitations`: `institution_id`, `student_id`, `email`, `name`, `role`, `token` (SHA-256 hash), `invited_by`, `expires_at`, `accepted_at`.
- `landing_enquiries`: `name`, `email`, `institution_name`, `institution_type`, `message`, `status` (`new|contacted|approved|rejected`), `institution_id`, `reviewed_by`, `reviewed_at`, `review_notes`.

### 3.3 Routes exercised (`routes/web.php`, `routes/auth.php`)
| Route name | Verb + URI | Middleware |
|------------|-----------|------------|
| `login` | `POST /login` | `guest` |
| `dashboard` | `GET /dashboard` | `auth,verified` |
| `password.setup` | `GET /password/setup/{invitation}` | `signed` |
| `password.setup.store` | `POST /password/setup/{invitation}` | — |
| `ssa.dashboard` | `GET /platform` | `permission:monitoring.view` |
| `ssa.enquiries.index` | `GET /platform/enquiries` | `permission:monitoring.view` |
| `ssa.enquiries.approve` | `POST /platform/enquiries/{enquiry}/approve` | `permission:monitoring.manage` |
| `ssa.enquiries.contact` | `POST /platform/enquiries/{enquiry}/contact` | `permission:monitoring.manage` |
| `meals.students.index` | `GET /meals/students` | `permission:students.view` |
| `meals.students.store` | `POST /meals/students` | `permission:students.manage` |
| `settings.currency` | `GET /settings/currency` | `permission:currency.view` |
| `settings.currency.store` | `POST /settings/currency` | `permission:currency.manage` |
| `settings.users.index` | `GET /settings/users` | `permission:users.view` + `role:Software Super Admin\|Institution Admin` |
| `settings.theme.edit` / `.update` / `.reset` | `/settings/theme` | `auth,verified` (no permission — personal preference) |
| `member.dashboard` | `GET /my/dashboard` | `permission:meals.view` + `role:Member` |

### 3.4 DOM selectors (from the React pages)
- **Login** (`resources/js/Pages/Auth/Login.jsx`): heading `Welcome back`, `#email`, `#password`, button `Sign In to Dashboard`.
- **Enquiries** (`resources/js/Pages/SSA/Enquiries.jsx`): heading `Landing Enquiries`, button `Approve & Provision`, modal title `Provision institution`, option `7-Day Free Trial`.
- **Student roster** (`resources/js/Pages/Meals/Students/Index.jsx` + `MemberFormModal.jsx`): `Add Member`/`Add Student` button, form id `member-form`, fields `#name`, `#roll` (the `Field` component sets `id === name`).
- **Currency** (`resources/js/Pages/Settings/CurrencyManager.jsx`): `Currency & Formatting`, `#symbol`, `#decimal_precision`, button `Save Settings`.
- **Password setup** (`resources/js/Pages/Auth/PasswordSetup.jsx`): heading `Welcome - set your password`, `#name`, `#password`, `#password_confirmation`, button `Activate my account`.
- **Theme** (`resources/js/Pages/Settings/ThemeCustomizer.jsx` + `Components/ThemeProvider.jsx`): heading `Theme Customizer`, `Save My Theme`, `Reset to default`; the root `<html>` receives **`data-theme-mode="dark"`** (and the `.dark` class) via `ThemeProvider`.

> **Terminology caveat:** a `university_dorm` workspace renders the member label as **"Student"** (see `Institution::TYPES`), so the roster toolbar button reads **"Add Student"** — the tests wait for the real label.

### 3.5 Real flash messages asserted
- `Enquiry marked as contacted.` (`LandingEnquiryController::markContacted`)
- `Institution "{name}" provisioned on {label}. Welcome email sent to {email}` (`LandingEnquiryController::approve`)
- `{name} added to the roster.` (`Meals\StudentController::store`)
- `Currency settings saved for this institution.` (`SettingsController::store`)
- `Your password is set. Please sign in with your new credentials.` (`PasswordSetupController::store`)
- `Theme saved. It will follow you to every device you sign in from.` / `Theme reset to the platform default.` (`ThemeController`)

---

## 4. How the Dusk environment is bootstrapped

### 4.1 Dependencies
`laravel/dusk` `^8.6` is in `composer.json` (`require-dev`). If the Dusk scaffolding is ever missing:

```powershell
cd "F:\Mahfuz\Mobile App\transaction-tracker"
php artisan dusk:install
```

`tests/DuskTestCase.php` already exists and is hardened (below).

### 4.2 A running web server is required
Dusk drives a **real browser against a real HTTP server**. Neither `php artisan dusk` nor `DuskTestCase` starts `php artisan serve` for you — you must have the app listening on `APP_URL` (`http://127.0.0.1:8000`) before the run. The `dusk` CI job starts it and blocks until `/up` answers (`ci.yml`). Locally:

```powershell
# terminal 1
php artisan serve --host=127.0.0.1 --port=8000
# terminal 2
php artisan dusk
```

This is exactly why the suite uses **`DuskDatabase`** (committed, file-backed DB) instead of `RefreshDatabase` — see §2.2 and §7.

### 4.3 Headless Chrome configuration (`tests/DuskTestCase.php`)
- **`prepare()`** starts a local ChromeDriver on **port 9515** (skipped under Sail).
- **`driver()`** builds `ChromeOptions` with:
  - `--window-size=1920,1080` — a fixed desktop viewport so Tailwind `lg:`/`sm:` breakpoints resolve identically everywhere (deterministic assertions on desktop-only markup such as the docked sidebar). `hasHeadlessDisabled()` swaps this for `--start-maximized`.
  - `--disable-search-engine-choice-screen`, `--disable-smooth-scrolling`, `--no-first-run` — removed first-run/scroll flakiness.
  - `--disable-dev-shm-usage` — moves shared memory off `/dev/shm` (Chrome hangs there under Docker/CI otherwise).
  - **`--headless=new`**, `--disable-gpu` and **`--no-sandbox`** — applied **unless** headless is disabled; `--no-sandbox` is required when Chrome runs as root in CI containers.
  - A block of `--disable-*` stabilisers (background networking/timers, breakpad, notifications, sync, etc.) and `--log-level=3`.
- The driver talks to `DUSK_DRIVER_URL` (default `http://localhost:9515`).

### 4.4 Running visibly for debugging
Dusk honours `hasHeadlessDisabled()`. Add to `.env.dusk.local`:

```
DUSK_HEADLESS_DISABLED=true
```

then run a single test — a real Chrome window opens and you can watch the flow.

### 4.5 Test-database isolation (`.env.dusk.local`)
Dusk swaps in a dedicated environment file for the run: it backs up your `.env`, copies **`.env.dusk.local`** (falling back to `.env.dusk`) over `.env`, and restores the backup afterwards (`Laravel\Dusk\Console\DuskCommand`). The repository ships `.env.dusk.local` (git-ignored by `.gitignore` — never committed):

```dotenv
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:...              # a real key; see the first-run checklist
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
DB_DATABASE=database/dusk.sqlite
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=log
REGISTRATION_DEFAULT_ROLE=Member

# Optional: run a visible browser while debugging
# DUSK_HEADLESS_DISABLED=true
```

Create the isolated database file once:

```powershell
New-Item -ItemType File -Force "database\dusk.sqlite"
```

**Why isolation matters here:** Dusk uses a **file-backed** SQLite DB (`database/dusk.sqlite`) shared by the test process and the server process, so your development database (and its seeded institution) is never touched. `Queue`/`Cache`/`Session` are kept in-memory/array where possible so no external services are needed.

---

## 5. Running the suite

All commands assume the project root:

```powershell
cd "F:\Mahfuz\Mobile App\transaction-tracker"
```

### 5.1 Full browser suite
```powershell
php artisan dusk
```

### 5.2 One class / one method
```powershell
php artisan dusk tests/Browser/SoftwareSuperAdmin/Login/Feature/LoginTest.php
php artisan dusk tests/Browser/InstituteAdmin/Members/Feature/CreateTest.php
php artisan dusk --filter=test_institute_admin_adds_a_member_to_the_roster
```

### 5.3 Watch it run (visible Chrome)
```powershell
# .env.dusk.local must contain DUSK_HEADLESS_DISABLED=true
php artisan dusk tests/Browser/Member/ThemeCustomizer/Feature/ThemeCustomizerTest.php
```

### 5.4 Front-end build prerequisite
Dusk loads the real Vite bundle, so the assets must exist:

```powershell
npm run build      # production assets  (or `npm run dev` while iterating)
```

### 5.5 First-run checklist
```powershell
# 1. Ensure the Dusk env file exists and has an APP_KEY
php artisan key:generate --show          # copy into .env.dusk.local

# 2. Create the isolated test database
New-Item -ItemType File -Force "database\dusk.sqlite"

# 3. Build assets
npm run build

# 4. Start the app server (separate terminal) and run the suite
php artisan serve --host=127.0.0.1 --port=8000
php artisan dusk
```

---

## 6. Debugging playbook

| Symptom | Cause & fix |
|---------|-------------|
| `waitForLocation('/dashboard')` hangs / form login never lands | The server process cannot see test data, or no server is running. Confirm `php artisan serve` is up on `APP_URL` **and** the suite uses `DuskDatabase` (committed file DB), not `RefreshDatabase`. |
| `ChromeDriver ... connection refused` | Port 9515 busy or driver not started. Close stray `chromedriver.exe`, or run `php artisan dusk:chrome-driver --detect`. |
| `Facebook\WebDriver\Exception\... invalid session id` | Chrome crashed (common in Docker). `--no-sandbox` and `--disable-dev-shm-usage` are already set in `DuskTestCase`; ensure the container has enough `/tmp`. |
| Assertion on an element that "exists" fails | The React page has not hydrated. Critical steps use `waitFor`/`waitForText` before interacting — increase the timeout (2nd arg) if your machine is slow. |
| `waitForText('Software Super Admin')` times out | The element is styled with Tailwind `uppercase`, so `getText()` returns uppercase. Use `waitForTextCaseInsensitive(...)` (already used where needed). |
| `419` from an in-test `post()` | CSRF middleware is active. Route server-side assertions through `httpAs($user)` (it disables only the CSRF middleware). |
| `403` where you expected content | Working as designed: the SSA console (`monitoring.view`) and the User Manager (`role:...` + `users.view`) are permission-gated. The tests assert this boundary explicitly. |
| Old data leaks between runs / duplicate `users.email` | You are not using `.env.dusk.local`, or `truncateDuskTables()` did not run (it aborts if `tearDown()` throws early). Ensure `DB_DATABASE` points at `database/dusk.sqlite`. |
| First test of a class redirected away from `/login` | A prior test left a session cookie. `DuskTestCase::tearDown()` clears the browser's cookies per test; run the class, not a bare method, if you bypass it. |
| Role middleware errors (`role not found`) | The core roles were not seeded. Call `$this->seedRbac()` in the test before creating users. |
| Screenshots / console output | On failure Dusk writes a screenshot and the browser console log to `tests/Browser/screenshots` and `tests/Browser/console`. Inspect these first. |

Useful artisan helpers:
```powershell
php artisan dusk:fails           # re-run only the last failing tests
php artisan dusk:chrome-driver --detect
```

---

## 7. Determinism notes

- Each test runs against the **committed** Dusk database; `DuskDatabase::truncateDuskTables()` clears the domain tables between tests, so state never bleeds across tests (no rollback transaction — a rollback would hide rows from the server process).
- The browser's cookies are cleared in `tearDown()` (Dusk reuses one browser per class), so each test starts from a clean guest session.
- Fixtures are built with explicit, unique emails/rolls (`DuskSupport` randomises institution names to satisfy the `institutions.slug` UNIQUE index) — no reliance on ordering.
- Text waits are case-insensitive where Tailwind `uppercase` would otherwise break a literal match.
- Assertions on tenant isolation read the database directly (e.g. `Student::where('institution_id', ...)`) **and** the rendered DOM, covering both the query scope and the UI.
- Time-sensitive assertions avoid hard-coded dates; the trial window is asserted via `Institution::isOnTrial()` rather than an exact timestamp.
- The `phpunit.dusk.xml` config raises `memory_limit` to `2G` (a full Dusk class boots a Laravel app + browser driver, which can exceed PHP's default 128M).

---

## 8. The suite is Dusk-only (by policy)

**Every requirement is verified through a real browser.** There is deliberately
no Unit or Feature suite: `tests/Unit` and `tests/Feature` have been REMOVED, and
`phpunit.xml` now points at `tests/Browser` so a stray `php artisan test` runs the
browser suite rather than silently reporting "0 tests".

```
tests/
├── DuskTestCase.php           ← base class (driver, DB, teardown)
├── Browser/
│   ├── Support/               ← DuskSupport (fixtures), DuskDatabase (shared DB)
│   └── {Role}/{Module}/{TestType}/
└── (no Unit/, no Feature/)
```

### Why browser-only

A browser test asserts the thing a user actually experiences: the page renders,
the control is clickable, the flash message appears. Several real defects in this
codebase were invisible to HTTP-level tests and only surfaced in a browser — for
example a React provider that crashed the whole tree (blank page, HTTP 200), and
an onboarding modal that never rendered while every server-side prop was correct.

### What this costs (worth stating plainly)

The mobile **JSON API** is no longer covered by an automated test. Dusk drives a
browser against the web routes; it cannot exercise `/api` (bearer-token auth, no
cookies, no HTML). The multi-tenant isolation assertions that lived in
`tests/Feature/Api/Tenancy` are gone with it. If API coverage is wanted back, it
needs a separate HTTP-level suite — browser tests cannot substitute for it.

### How the commands map

| Command | Config | Runs |
|---------|--------|------|
| `php artisan dusk` | `phpunit.dusk.xml` | **The browser suite** — 242 tests, 61 classes. |
| `composer test` | — | `config:clear` then `php artisan dusk`. |

The CI workflow runs the style check, then the Dusk suite in the `dusk` job (see
`.github/workflows/ci.yml`).

---

## 8.1 Environment prerequisites that silently break the suite

These three cost hours to diagnose because each fails in a way that does **not**
point at its cause. Check them first.

### 1. ChromeDriver must match the installed Chrome

```
session not created: This version of ChromeDriver only supports Chrome version 154
Current browser version is 153.0.8010.53
```

Chrome auto-updates; the bundled driver does not. **Every** browser test fails at
session creation, so the suite reports a wall of failures that look like broken
features. Install the matching driver:

```powershell
php artisan dusk:chrome-driver 153     # use your Chrome's major version
```

> On Windows that command can fail at its rename step while still downloading
> correctly. Verify with `vendor\laravel\dusk\bin\chromedriver-win.exe --version`
> and copy the downloaded binary over the old one manually if needed.

### 2. `public/hot` must not exist

If `public/hot` is present, Laravel emits asset URLs pointing at the **Vite dev
server** instead of `public/build`. If Vite is not running (the normal case for a
Dusk run), the browser loads no JavaScript at all: the page returns HTTP 200,
the DOM is present, `bodyChars` is 0, and there are **no console errors**.

```powershell
Remove-Item public\hot -Force   # then confirm: php artisan dusk
```

A `npm run dev` left running in another terminal will recreate it.

### 3. SQLite must run in WAL mode

Dusk drives a browser against a **separate `php artisan serve` process**, so two
processes write to the same SQLite file. Without WAL that produces:

```
SQLSTATE[HY000]: General error: 5 database is locked
SQL: insert into "institutions" ...
```

It surfaces as ONE unrelated-looking test failing with a 500 while the rest pass
(for example "approve an enquiry", which provisions an institution). The pragmas
are declared in `config/database.php` so **both** processes receive them — the
serve process never runs the test bootstrap, so setting them there is not enough.

---

## 8.2 Fixture conventions that keep the suite honest

### The first-login tour is dismissed by default

`DuskSupport::makeTenantUser()` / `makeSuperAdmin()` set
`onboarding_completed_at`, so the guided tour does not open on top of the page in
the ~40 browser tests that are not about onboarding. Its overlay is
`fixed inset-0 z-[100]`, and a test that ignores it fails as
`ElementClickInterceptedException` or a 20-second wait for an element that IS
rendered — neither of which reads as "the tour is open".

`tests/Browser/Onboarding` opts IN by passing
`['onboarding_completed_at' => null]`.

### Guest routes need `httpAsGuest()`, not `httpAs()`

`httpAs()` calls `actingAs()`. That is correct for an authenticated route and
**wrong** for one behind `guest` — the request is redirected before the controller
runs, so a registration test sees a 302 with no validation errors and no created
row, which looks like "registration is broken".

```php
$this->httpAsGuest()->post('/register', [...])->assertSessionHasErrors('invite_code');
```

### A tenant switch must happen IN the browser session

The switch route is **PATCH-only** and session-scoped. Switching via `httpAs()`
mutates the test client's session; the browser then renders the GLOBAL view and
the impersonation banner correctly does not appear. Drive it through the UI:

```php
$browser->loginAs($ssa)->visit('/settings/institutions');
$this->dismissOnboarding($browser);
$browser->press('Access Dashboard')->waitForText('Now viewing "...".', 20);
```

---

## 9. Files (current layout)

```
tests/
├── DuskTestCase.php                    (uses DuskDatabase: committed file DB + cookie reset)
├── Browser/
│   ├── Support/
│   │   ├── DuskSupport.php             (fixtures + step() logging + httpAs())
│   │   └── DuskDatabase.php            (committed Dusk DB + truncation)
│   ├── SoftwareSuperAdmin/…            ({Login, Dashboard, <Module>}/Feature/*)
│   ├── InstituteAdmin/…                ({Login, Dashboard, TrialRequest, <Module>}/Feature/*)
│   ├── MealManager/…                   ({Login, Dashboard, <Module>}/Feature/*)
│   ├── Member/…                        ({Login, Dashboard, <Module>}/Feature/*)
│   ├── Guest/Welcome/…                 (landing page only)
│   └── Pages/                          (unchanged Dusk scaffolding)
├── Feature/…                           (Role/Module + Api/* — see §8)
└── Unit/…                              (Support/MoneyTest.php)
```

No application code is modified by the test suite — it exists purely to verify behaviour that ships in the app.

### Verifying the suites load
```powershell
php artisan dusk --list-tests          # 106 Browser tests across 61 classes
php artisan test --list-tests          # 65 Unit + Feature tests
php artisan dusk --filter=InstituteAdmin   # run one role's subtree
```
