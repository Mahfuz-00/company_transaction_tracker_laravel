# Laravel Dusk Test Suite — Process & Execution Documentation

> **Project:** `transaction-tracker` (Laravel 12 + Inertia.js + React monolith)
> **Working directory:** `F:\Mahfuz\Mobile App\transaction-tracker`
> **Scope:** A role-based, hierarchical (Dusk) browser suite for ALL five audiences — Software Super Admin, Institute Admin, Meal Manager, Member, Guest.

Everything in this document and in the test classes was derived from the **actual code** in this repository. No route, column, selector, or flash message was invented.

---

## 1. Structure — role-first hierarchy
Tests are organised by ROLE (in the required order), then by Login / Dashboard / Modules, with one focused leaf folder per module (and a sub-folder per action where the structure calls for it).

```
tests/Browser/
├── SoftwareSuperAdmin/
│   ├── Login/LoginTest.php
│   ├── Dashboard/DashboardTest.php
│   └── Modules/
│       ├── LandingEnquiries/{List,Approve,MarkContacted,Reject}
│       ├── InstitutionRegistry/{List,Create,Toggle,Switch}
│       ├── Monitoring/  Plans/  Broadcasts/  Analytics/  GlobalAudit/
│       ├── Trials/  UserManager/
├── InstituteAdmin/
│   ├── Login/LoginTest.php
│   ├── Dashboard/DashboardTest.php
│   ├── TrialRequest/TrialRequestTest.php   ← guest request → SSA approval
│   └── Modules/
│       ├── Members/{List,Create,Edit,Delete}
│       ├── Currency/  Theme/  Departments/  Deposits/  MealEntries/
│       ├── Expenses/  Vendors/  Reports/  Subsidies/  ClaimReview/
│       ├── UserManager/  Institution/  SubsidySources/  ActivityLog/  EmailLog/
├── MealManager/
│   ├── Login/  Dashboard/
│   └── Modules/{Members,Deposits,MealEntries,Expenses,Vendors,Reports,Subsidies,ClaimReview,Currency,Theme}
├── Member/
│   ├── Login/  Dashboard/
│   └── Modules/{Meals,Deposits,Analytics,ThemeCustomizer,Claims,Profile}
├── Guest/
│   └── Welcome/WelcomeTest.php
└── Support/DuskSupport.php          ← shared fixtures + step() logging trait
```

Every authenticated-role test follows the same sequence: **Login → land on the dedicated dashboard → then exercise each module one by one.** Guest tests only the public landing page.

### What each role's suite proves
| Role | Highlights |
|------|-----------|
| **Software Super Admin** | Global login → `/platform`; landing-enquiry **approve → 7-day trial provisioning**; institution registry create/switch/toggle; monitoring subscription update; plans; broadcasts; cross-tenant isolation. |
| **Institute Admin** | Scoped `/dashboard`; roster isolated by `institution_id`; member CRUD; currency config persists; departments/deposits/entries/expenses/vendors/subsidies/claims; **cross-institution user management refused**. |
| **Meal Manager** | **Assignment-scoped** roster/deposits/entries (only `students.manager_id = me`); records expenses/vendors; claim review restricted to assigned members; view-only currency/subsidies. |
| **Member** | Personal `/my/dashboard`, own meals/deposits/analytics; raises claims; **dark-mode toggle persists to `users.theme`**; profile + password change; blocked from admin consoles. |
| **Guest** | Landing page renders; demo form writes a real `LandingEnquiry`; public pricing grid reflects SSA-configured plans. |

### Console progress logging
Per the requirement, **every test step** emits a labelled dump line so a developer can follow the run:

```php
dump("==== STEP: [Role] → [Module] → [Action] | Line ~XX ====");
```

This is centralised in `DuskSupport::step($role, $module, $action, $line)` — call it with `__LINE__` so the output points at the exact line in the test.

---

## 2. Verified source facts used by the tests

These were read directly from the codebase — they are the "contract" the tests assert against.

### 2.1 Roles & access tiers (`database/seeders/RolesAndPermissionsSeeder.php`)
Exactly four core roles:
- `Software Super Admin` — the **global** role; holds *everything*, including `monitoring.*` / `institutions.*`.
- `Institution Admin` — operational, **scoped** to one institution; no `monitoring.*` / `institutions.*`.
- `Meal Manager` — runs the mess.
- `Member` — `meals.view`, `transactions.view`, `claims.view`, `claims.submit`, `notifications.view` only.

Global vs tenant is decided by `User::isSuperAdmin()` (role-based) **and** `users.institution_id` being `null`.

### 2.2 Multi-tenant columns (migrations, verified)
- `users`: `institution_id` (nullable FK), `invitation_pending`, `must_change_password`, `password_changed_at`, `setup_completed_at`, `theme` (json), `designation`, `status`, `phone`, `last_login_at`, `avatar_path`.
- `institutions`: `name`, `slug`, `type`, `currency_code`, `currency_settings` (json), `timezone`, `terminology` (json), `settings` (json), `subsidy_mode`, `subscription_plan`, `subscription_status`, `subscription_amount`, `subscription_started_at`, `subscription_renews_at`, `member_limit`, `health_notes`, `last_reviewed_at`, `onboarding_mode`, `trial_started_at`, `trial_ends_at`, `trial_reminder_sent_at`, `converted_at`, `is_active`, `invite_code`.
- `students`: `institution_id`, `user_id`, `manager_id`, `name`, `roll`, `department_id`, `join_date`, `status`.
- `member_invitations`: `institution_id`, `student_id`, `email`, `name`, `role`, `token` (SHA-256 hash), `invited_by`, `expires_at`, `accepted_at`.
- `landing_enquiries`: `name`, `email`, `institution_name`, `institution_type`, `message`, `status` (`new|contacted|approved|rejected`), `institution_id`, `reviewed_by`, `reviewed_at`, `review_notes`.

### 2.3 Routes exercised (from `routes/web.php`)
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

### 2.4 DOM selectors (from the React pages)
- **Login** (`resources/js/Pages/Auth/Login.jsx`): heading `Welcome back`, `#email`, `#password`, button `Sign In to Dashboard`.
- **Enquiries** (`resources/js/Pages/SSA/Enquiries.jsx`): heading `Landing Enquiries`, buttons `Approve & Provision`, `Mark contacted`, modal title `Provision institution`, option `7-Day Free Trial`.
- **Student roster** (`resources/js/Pages/Meals/Students/Index.jsx` + `MemberFormModal.jsx`): `Add Member` button, form id `member-form`, fields `#name`, `#roll` (the `Field` component sets `id === name`).
- **Currency** (`resources/js/Pages/Settings/CurrencyManager.jsx`): `Currency & Formatting`, `#symbol`, `#decimal_precision`, button `Save Settings`.
- **Password setup** (`resources/js/Pages/Auth/PasswordSetup.jsx`): heading `Welcome - set your password`, `#name`, `#password`, `#password_confirmation`, button `Activate my account`.
- **Theme** (`resources/js/Pages/Settings/ThemeCustomizer.jsx` + `ThemeProvider.jsx`): heading `Theme Customizer`, `Save My Theme`, `Reset to default`; the root `<html>` receives **`data-theme-mode="dark"`** and the `.dark` class.

### 2.5 Real flash messages asserted
- `Enquiry marked as contacted.` (LandingEnquiryController)
- `...provisioned on a 7-day free trial...` (LandingEnquiryController::approve)
- `Sadia Islam added to the roster.` → pattern `{name} added to the roster.` (StudentController::store)
- `Currency settings saved for this institution.` (SettingsController::store)
- `Your password is set. Please sign in with your new credentials.` (PasswordSetupController::store)
- `Theme saved. It will follow you to every device you sign in from.` / `Theme reset to the platform default.` (ThemeController)

---

## 3. How the Dusk environment is bootstrapped

### 3.1 Dependencies
`laravel/dusk` `^8.6` is already in `composer.json` (`require-dev`). If the Dusk scaffolding is ever missing:

```powershell
cd "F:\Mahfuz\Mobile App\transaction-tracker"
php artisan dusk:install
```

`tests/DuskTestCase.php` already existed; it has been hardened (below).

### 3.2 Headless Chrome configuration (`tests/DuskTestCase.php`)
- **`prepare()`** starts a local ChromeDriver on **port 9515** (skipped under Sail, where the container provides the driver).
- **`driver()`** builds `ChromeOptions` with:
  - `--window-size=1920,1080` — a fixed desktop viewport so Tailwind `lg:`/`sm:` breakpoints resolve identically on every machine (deterministic assertions on desktop-only markup such as the docked sidebar).
  - `--disable-search-engine-choice-screen`, `--disable-smooth-scrolling` — removed first-run/scroll flakiness.
  - `--disable-dev-shm-usage` — moves shared memory off `/dev/shm` (Chrome hangs there under Docker/CI otherwise).
  - **`--headless=new`** and **`--no-sandbox`** — applied unless headless is disabled; `--no-sandbox` is required when Chrome runs as root in CI containers.
- The driver talks to `DUSK_DRIVER_URL` (default `http://localhost:9515`).

### 3.3 Running visibly for debugging
Dusk's `hasHeadlessDisabled()` honours the env flag. Add to `.env.dusk.local`:

```
DUSK_HEADLESS_DISABLED=true
```

then run a single test — a real Chrome window opens and you can watch the flow.

### 3.4 Test-database isolation
Dusk runs against a **real HTTP server + real database**, so the `:memory:` SQLite used by PHPUnit is not appropriate here. Isolation is achieved by a **dedicated Dusk environment file**:

Create **`.env.dusk.local`** (git-ignored by convention; never committed):

```dotenv
APP_NAME="transaction-tracker (dusk)"
APP_ENV=dusk
APP_KEY=            # copy the value from your main .env (php artisan key:generate --show)
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=sqlite
DB_DATABASE=F:/Mahfuz/Mobile App/transaction-tracker/database/dusk.sqlite

SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array

# Optional: run a visible browser while debugging
# DUSK_HEADLESS_DISABLED=true
```

Create the isolated database file once:

```powershell
New-Item -ItemType File -Force "database\dusk.sqlite"
```

**Why isolation matters here:** every test class uses `RefreshDatabase`, so Dusk migrates the *isolated* SQLite file per run and rolls back afterwards. Your development database (and its seeded institution) is never touched. `MAIL_MAILER=array` / `QUEUE_CONNECTION=sync` keep the provisioning and welcome emails in-memory so the tests never attempt a real SMTP send.

> **Roles are seeded per test.** The trait `BuildsTenantFixtures::seedCoreRolesAndPermissions()` runs `RolesAndPermissionsSeeder` inside each test, because `RefreshDatabase` wipes the roles the Spatie middleware (`role:...`) and the app guards (`User::isSuperAdmin()`) depend on.

---

## 4. Running the suite

All commands assume the project root:

```powershell
cd "F:\Mahfuz\Mobile App\transaction-tracker"
```

### 4.1 Full browser suite
```powershell
php artisan dusk
```

### 4.2 One class / one method
```powershell
php artisan dusk tests/Browser/SoftwareSuperAdminWorkflowTest.php
php artisan dusk --filter=test_ssa_sees_landing_enquiries_and_approves_one_for_a_seven_day_trial
```

### 4.3 Watch it run (visible Chrome)
```powershell
# .env.dusk.local must contain DUSK_HEADLESS_DISABLED=true
php artisan dusk tests/Browser/MemberOnboardingAndThemeTest.php
```

### 4.4 Front-end build prerequisite
Dusk loads the real Vite bundle, so the assets must exist:

```powershell
npm run build      # production assets  (or `npm run dev` while iterating)
```

### 4.5 First-run checklist
```powershell
# 1. Ensure the Dusk env file exists and has an APP_KEY
php artisan key:generate --show          # copy into .env.dusk.local

# 2. Create the isolated test database
New-Item -ItemType File -Force "database\dusk.sqlite"

# 3. Build assets
npm run build

# 4. Run the suite
php artisan dusk
```

---

## 5. Debugging playbook

| Symptom | Cause & fix |
|---------|-------------|
| `ChromeDriver ... connection refused` | Port 9515 busy or driver not started. Close stray `chromedriver.exe`, or run `php artisan dusk:chrome-driver --detect`. |
| `Facebook\WebDriver\Exception\... invalid session id` | Chrome crashed (common in Docker). `--no-sandbox` and `--disable-dev-shm-usage` are already set in `DuskTestCase`; ensure the container has enough `/tmp`. |
| Assertion on an element that "exists" fails | The React page has not hydrated. Every critical step uses `waitFor`/`waitForText` before interacting — increase the timeout (2nd arg) if your machine is slow. |
| `403` where you expected content | Working as designed: the SSA console (`monitoring.view`) and the User Manager (`role:...` + `users.view`) are permission-gated. The tests assert this boundary explicitly. |
| Old data leaks between runs | You are not using `.env.dusk.local`. Confirm `DB_DATABASE` points at `database/dusk.sqlite`, not your dev DB. |
| Role middleware errors (`role not found`) | The core roles were not seeded. `BuildsTenantFixtures::seedCoreRolesAndPermissions()` handles this per test; call it in any new test before creating users. |
| Screenshots / console output | On failure Dusk writes a screenshot and the browser console log to `tests/Browser/screenshots` and `tests/Browser/console`. Inspect these first. |

Useful artisan helpers:
```powershell
php artisan dusk:fails           # re-run only the last failing tests
php artisan dusk:chrome-driver --detect
```

---

## 6. Determinism notes

- Each test class uses `RefreshDatabase`, so state never bleeds between tests.
- Fixtures are built with explicit, unique emails/rolls — no reliance on ordering.
- Assertions on tenant isolation read the database directly (`Student::where('institution_id', ...)`) **and** the rendered DOM (`assertDontSee('Cross Tenant Boarder')`), covering both the query scope and the UI.
- Time-sensitive assertions avoid hard-coded dates; the trial window is asserted via `Institution::isOnTrial()` rather than an exact timestamp.

---

## 7. Files added / changed
```
tests/
├── DuskTestCase.php                    (edited: headless + CI-stable Chrome flags)
└── Browser/
    ├── Support/DuskSupport.php         (new: fixtures + step() console logging)
    ├── SoftwareSuperAdmin/…            (Login, Dashboard, Modules/*)
    ├── InstituteAdmin/…                (Login, Dashboard, TrialRequest, Modules/*)
    ├── MealManager/…                   (Login, Dashboard, Modules/*)
    ├── Member/…                        (Login, Dashboard, Modules/*)
    ├── Guest/Welcome/…                 (landing page only)
    └── Pages/                          (unchanged Dusk scaffolding)
```

No application code was modified — the suite exists purely to verify behaviour that ships in the app.

### Test count
`php artisan dusk --list-tests` reports **105 tests** across the five roles.

### Verifying the suite loads
```powershell
php artisan dusk --list-tests      # confirms every class + method is discovered
php artisan dusk                   # runs the full browser suite
php artisan dusk --filter=InstituteAdmin   # runs one role's subtree
```
