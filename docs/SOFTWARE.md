# Software Documentation (Developer Guide)

How this system is organised, the maths it solves, and the patterns used. Aimed
at someone joining the codebase for the first time.

---

## 1. The stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | React 18 + Inertia.js (no separate API needed for the web UI) |
| Styling | Tailwind CSS |
| Database | SQLite in dev (swap `DB_CONNECTION` for MySQL/Postgres in production) |
| Auth | Session-based for web; **Sanctum tokens** for the mobile API |
| Roles | `spatie/laravel-permission` |
| Charts | Chart.js via `react-chartjs-2` |

**Inertia** is the key idea: there is no separate SPA build serving JSON to the
web UI. A controller returns `Inertia::render('Page/Name', $props)` and Inertia
renders that React component with those props — the same PHP routing and
validation as a classic app, with a React front end.

---

## 2. Domain model

```
Institution (the tenant: a dorm, hall, office, mess)
 ├── User            (belongs to an institution; has roles)
 ├── Student         (a "member" — the roster record)
 │    ├── Deposit    (money in)
 │    └── MealEntry  (breakfast/lunch/dinner per day)
 ├── Department      (groups members)
 ├── Vendor          (supplier; one is the institution's own "hub")
 ├── Subsidy         (institutional funding)
 ├── SubsidySource   (admin-managed funders + default %)
 └── MealRateSetting (how the per-meal rate is derived)

Transaction (the ledger — every money movement, in or out)
ActivityLog (immutable audit trail)
MemberInvitation (emailed signup links)
```

> **Naming note.** The model is called `Student` for historical reasons, but it
> represents a **member** in the UI. The terminology layer maps it to the
> institution's preferred noun.

---

## 3. The maths — where every number comes from

All financial logic lives in **one place**: `app/Support/FinanceCalculator.php`.
Controllers call it; nothing else computes money. This is deliberate — it is the
reason the dashboard, reports and API can never disagree.

### The core identity

```
per-meal rate = total expense ÷ total meals
```

- **Total expense** = `SUM(transactions.amount WHERE type = 'out')` for the month.
- **Total meals** = `SUM(breakfast + lunch + dinner)` across `meal_entries`
  for the month.

Note meals are summed from the *columns*, not counted as rows — a member can eat
three meals in a single day's row.

### Per-member figures

```
meal cost = meals eaten × per-meal rate
balance   = deposits − meal cost
```

A negative balance means the member owes the pool.

### Subsidy coverage

```
subsidy coverage % = subsidy money ÷ meal cost × 100
member funded %    = deposits ÷ meal cost × 100
```

### The strict balance rule

Reserve subsidies ("credit_behind") are held back and only used once a member's
own deposits are exhausted:

```php
$shortfall = max(0, $mealCost - $ownDeposits);
$credit = min($reservePool, $shortfall);
```

See `Subsidy::creditAvailableFor()`.

### The forecast

`FinanceCalculator::forecast()` implements a simple, explainable model:

1. **Collect** the last 3 months of meals, expenses, deposits and subsidies.
2. **Weight** them linearly — the newest month counts 3×, the oldest 1× —
   because a growing mess should be projected on its recent trend.
3. **Growth rate** = `(last − first) ÷ first ÷ (n − 1)`, **clamped to ±35%** so a
   single unusual month cannot blow the projection up.
4. **Project** next month's meals and expense by applying that growth.
5. **Subsidy required** = `projected cost × target_subsidy_ratio`.

The target ratio defaults to **20%** (the "80/20 rule"): members carry 80%,
subsidies 20%. It is configurable in `meal_rate_settings`.

> Everything is `round()`ed and clamped deliberately. A forecast that produces
> `−4,000,000` because of a zero-baseline month is worse than no forecast.

---

## 4. Directory map

```
app/
├── Http/
│   ├── Controllers/          Web (Inertia) controllers
│   │   ├── Api/              JSON API for the mobile app
│   │   └── Meals/            Meal module: students, deposits, entries,
│   │                         expenses, subsidies, reports
│   └── Middleware/
│       └── HandleInertiaRequests.php   Shares auth, institution, theme,
│                                        terminology, currency with every page
├── Models/                   Eloquent models
├── Support/
│   ├── FinanceCalculator.php ★ all money maths
│   ├── AuditLogger.php       writes audit rows
│   ├── RecordActivity.php    model observer → AuditLogger
│   ├── ReportExporter.php    Excel (SpreadsheetML) + PDF (print HTML)
│   └── Money.php             server-side currency formatting
resources/js/
├── Components/               Reusable UI (Sidebar, Modal, Field, Icon,
│                             GlobalLoadingIndicator, ThemeProvider)
├── Layouts/                  AuthenticatedLayout, MealsLayout, SettingsLayout
├── Pages/                    One component per Inertia page
└── Utils/                    can.js, useTerminology.js, useMoney.js,
                              navItems.js
database/
├── migrations/               Schema
└── seeders/                  Roles, institutions, demo data
docs/                         API.md, USER-MANUAL.md, SOFTWARE.md
```

---

## 5. Patterns to follow

### Money maths goes in FinanceCalculator

Never compute a total inline in a controller. Add a method to
`FinanceCalculator` and call it. That keeps one source of truth.

```php
$finance = new FinanceCalculator();
$snapshot = $finance->monthSnapshot('2026-09');
```

### Everything is month-scoped

Reports, deposits, expenses, subsidies and member figures all filter by
`YYYY-MM` via `FinanceCalculator::resolveMonth()`, which validates the format
and falls back to the current month. There are no free date ranges in the
user-facing UI.

### Shared props over per-page lookups

`HandleInertiaRequests::share()` puts `auth`, `institution`, `terms`,
`currency`, `theme` and `flash` on **every** response. A component reads them
from `usePage().props` — never fetches them again.

### Terminology, never hard-coded nouns

```jsx
const { t } = useTerminology();
<h2>{t('members')}</h2>   // "Students" | "Employees" | "Boarders"
```

`Institution::TYPES` holds the presets; the `terminology` JSON column holds
admin overrides; `terminologyMap()` merges them.

### Theming via CSS variables

`ThemeProvider` writes `--accent` / `--accent-soft` onto `:root` from the
institution's theme. Components use `bg-[var(--accent)]`, so changing the
accent repaints the app with no code change. `app.jsx` applies the theme
*before* React mounts to avoid a flash of the default colour.

### Auditing is automatic

Models are observed in `AppServiceProvider::boot()`:

```php
foreach ([Student::class, User::class, /* ... */] as $model) {
    $model::observe(RecordActivity::class);
}
```

`RecordActivity::updated()` builds a `{ field: { old, new }` diff and writes
it through `AuditLogger`. Controllers do nothing. Non-model events (login,
logout, invite, export) call `AuditLogger::log()` explicitly.

### One loader for the whole app

`GlobalLoadingIndicator` listens to Inertia's router events and shows a single
overlay spinner. It waits 260 ms before appearing (so fast navigations do not
flash) and holds for 180 ms once shown (so it never blinks). Individual forms
still use the inline `<Spinner />` for button-level feedback.

### Permissions

Route middleware: `->middleware('permission:students.manage')`.
Frontend: `const { can } = useCan(); if (can('students.manage')) { ... }`.

`Gate::before` grants the **Software Super Admin** every ability, so a new
permission never locks them out.

---

## 6. The API layer

`routes/api.php` + `app/Http/Controllers/Api/*`. Same models and services as the
web layer — only the response format differs.

- Auth: Sanctum tokens (`HasApiTokens` on `User`).
- Every collection returns `{ data, meta }`.
- `/api/meta` serves currency, terminology and enum lists so the mobile client
  never hard-codes them.

Full reference: [`docs/API.md`](./API.md).

---

## 7. Adding a feature — worked example

Say you want a "meal quality score" per day.

1. **Migration** — add `quality` to `meal_entries`.
2. **Model** — add `quality` to `$fillable`.
3. **Logic** — if it is a computed figure, add a method to
   `FinanceCalculator`; otherwise read the column.
4. **Controller** — return it in the existing `Inertia::render` props.
5. **UI** — render it in `Pages/Meals/Entries/Index.jsx`.
6. **API** — add it to `MealApiController`'s payload.
7. **Docs** — note it in `API.md` and `USER-MANUAL.md`.

That order matters: schema → model → logic → controller → UI → API → docs.

---

## 8. Testing & verification

```bash
php artisan test                 # PHPUnit feature/unit tests
npm run build                    # front end must compile
php artisan migrate --force      # schema must apply cleanly
php artisan tinker               # poke at models by hand
```

The browser check used throughout development lives in the repo as
`qa-*.mjs` scripts run through the browser-automation tool — they log in and
assert each page renders with no console errors.

**Before shipping a change:** build the front end, run migrations, then load the
affected pages in a browser and confirm zero console errors and zero failed
requests. Reading the code is not verification; looking at the page is.

---

## 9. Gotchas learned the hard way

- **Only one instance of a model's money maths.** Duplicated maths is how the
  dashboard and reports drifted apart in an earlier version.
- **`whereDate`, not `where`.** A `date` column stores `2026-09-16 00:00:00`;
  comparing it to `'2026-09-16'` with `where()` never matches.
- **Ambiguous columns on a join.** Once you `join('transactions')`, qualify
  every column — both tables have `created_at`, and SQLite rejects the ambiguous
  reference outright.
- **Sanitise the terminology map.** Blank overrides must be dropped so the type
  preset shows through again.
- **Signatures bind the host.** `URL::temporarySignedRoute` signs the absolute
  URL, so `APP_URL` must match the host actually served or emailed links fail.
- **`forceFormData` for uploads.** Any request carrying a file needs it, or the
  file is silently dropped.
