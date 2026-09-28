# NomNomytics

A multi-institution **SaaS for tracking shared meal money** — member **deposits**, daily **meal entries**, mess **expenses**, and institutional **subsidies** — settling who owes what through a per-meal rate. One Laravel 12 + Inertia/React monolith serves both the web console and a Sanctum-protected mobile JSON API.

> The one number everything revolves around: `per-meal rate = total expense ÷ total meals`.

---

## Stack

| Layer | Technology |
|---|---|
| Backend | **Laravel 12** (PHP 8.2+) |
| Frontend | **Inertia.js 2** + **React 18** (no separate SPA server for the web UI) |
| Styling | **Tailwind CSS** |
| i18n | **English + Bengali** (server `lang/`, shared to React via Inertia; add a language in 2 files) |
| Auth — web | Laravel session guards |
| Auth — mobile API | **Laravel Sanctum 4** (personal access tokens, 30-day lifetime) |
| Roles & permissions | **spatie/laravel-permission 6** |
| JS route helper | **Ziggy 2** |
| Bundler | **Vite 7** (`@vitejs/plugin-react`) |
| Database | **SQLite** in dev (swap `DB_CONNECTION` for MySQL/Postgres in production) |
| Charts | Chart.js via `react-chartjs-2` |

Inertia is the key idea: a controller returns `Inertia::render('Page/Name', $props)` and Inertia renders that React component with those props — the same PHP routing and validation as a classic app, with a React front end.

---

## Roles

There are exactly **four** core roles, seeded by `Database\Seeders\RolesAndPermissionsSeeder`:

| Role | Scope |
|---|---|
| **Software Super Admin** | Global. Platform settings, the Institution Registry, cross-tenant monitoring/analytics, and every audit trail. Holds `monitoring.*` / `institutions.*` — the only role whose queries can run without a tenant scope. |
| **Institution Admin** | Runs **one** institution: members, meals, deposits, expenses, subsidies, vendors, terminology and theme. No cross-tenant reach. |
| **Meal Manager** | Day-to-day operations within one institution: records meals, deposits and expenses. Read-only on subsidies and settings. |
| **Member** | Sees their **own** meals, deposits, balance and analytics only; can raise claims. No administrative console. |

---

## Quick start

**Prerequisites:** PHP 8.2+, Composer, Node.js 20+ (and a browser if you will run Dusk).

```bash
# 1. Environment
cp .env.example .env
php artisan key:generate

# 2. Database (create the SQLite file if it does not exist) + seed RBAC
#    (creates database/database.sqlite automatically on migrate if configured)
php artisan migrate --seed

# 3. Front-end assets
npm install && npm run build

# 4. Serve
php artisan serve
```

The app is then at `http://127.0.0.1:8000`.

### Or use the one-command bootstrap

```bash
composer setup
```

`composer setup` runs: `composer install` → create `.env` from `.env.example` → `php artisan key:generate` → `php artisan migrate` → `npm install` → `npm run build`. It migrates the schema **without seeding**, so add `php artisan db:seed` afterwards for the RBAC roles, the currency catalogue and the default institution.

> The seeders are production-shaped: `DatabaseSeeder` runs `RolesAndPermissionsSeeder`, `CurrenciesTableSeeder`, `InstitutionSeeder` and `SoftwareSuperAdminSeeder` — it deliberately creates **no** dummy users or sample data. Every seeder is idempotent (`firstOrCreate` keyed on a natural identifier) and **never overwrites an existing credential**, so `db:seed` is safe to run against a live database.

### Want realistic sample data locally?

A rich five-institution fixture set (members, deposits, meals, expenses, subsidies, vendors, menu votes, claims) lives in the **git-ignored** `MockDataSeeder`:

```bash
php artisan db:seed --class=MockDataSeeder      # create it
php artisan db:clear-dummy --force              # remove it again
```

Because the file is excluded from version control, the sample dataset **cannot** be deployed to production.

### Is the platform-owner account healthy?

If `admin@mahfuz.com` cannot sign in, diagnose before reaching for a manual seed:

```bash
php artisan ssa:doctor          # read-only diagnosis (exit 1 if unhealthy)
php artisan ssa:doctor --fix    # repair the safe subset
```

It checks the account exists, holds the global role, has a usable password, is active, and is correctly unbound from any institution — and never overwrites a working password.

---

## Testing & quality

| Command | What it runs |
|---|---|
| `php artisan dusk` | **The browser suite** (Laravel Dusk) — 242 tests, 61 classes. The suite is Dusk-only by policy: `tests/Unit` and `tests/Feature` were removed, and `phpunit.xml` points at `tests/Browser`. |
| `composer run phpcs` | Code style (PHP_CodeSniffer against `phpcs.xml`) |
| `npm run lint:nav-icons` | Sidebar icon-consistency gate (every module has a distinct, defined icon) |
| `npm run build` | Production Vite assets |

Dusk drives a real browser against a real server, so it needs built assets **and** a running `php artisan serve` (on `APP_URL`). See **[docs/DUSK_TESTING.md](docs/DUSK_TESTING.md)** for the full playbook.

---

## Documentation

| Document | Contents |
|---|---|
| [docs/USER_MANUAL.md](docs/USER_MANUAL.md) | End-user guide: role-by-role walkthroughs (Super Admin, Institution Admin, Meal Manager, Member) and the per-meal-rate maths. |
| [docs/SOFTWARE_ARCHITECTURE.md](docs/SOFTWARE_ARCHITECTURE.md) | Developer guide: stack, multi-tenancy, domain model + schema, theme engine, the API layer and testing. |
| [docs/API.md](docs/API.md) | Mobile JSON API reference (`/api`, Sanctum bearer tokens, envelopes, endpoints). |
| [docs/FLUTTER_MOBILE_APP.md](docs/FLUTTER_MOBILE_APP.md) | **Flutter mobile client spec**: Clean Architecture + BLoC, DI, Dio interceptors, offline-first sync, FCM, design tokens and the SSA exclusion policy. |
| [docs/mobile_app_ide_instructions.md](docs/mobile_app_ide_instructions.md) | **Master build spec for an IDE agent**: stack, the exact RBAC permission matrix, production folder tree, offline queue + conflict policy, FCM handling, memory-leak disposal rules, and a complete worked feature (Meal Voting: entity → repo → BLoC → UI → test). |
| [docs/DUSK_TESTING.md](docs/DUSK_TESTING.md) | Test-suite documentation: the Dusk browser suite (`106` tests, `{Role}/{Module}/Feature` hierarchy) plus the Unit/Feature suites, and how to run and debug each. |
| [docs/index.html](docs/index.html) | **Browsable HTML docs** (open in any browser). Generated from the markdown above by `npm run docs:build`. |

---

## Project layout (high level)

```
app/            Laravel application code (controllers, models, services, support)
database/       Migrations + seeders (RolesAndPermissionsSeeder, Currencies..., Institution...)
resources/js/   React + Inertia pages and components
routes/         web.php (console), api.php (mobile API), auth.php
tests/          Browser/ (Dusk, {Role}/{Module}/Feature), Feature/ (incl. Api/), Unit/
docs/           User manual, software guide, API reference, testing guide
```

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
