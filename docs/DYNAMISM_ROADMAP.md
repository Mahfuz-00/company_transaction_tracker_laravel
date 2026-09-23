# System Dynamism Benchmark & Roadmap

An honest evaluation of how *dynamic* (configurable without code changes) this
platform is today, plus the concrete structural hooks already in place for the
next tier of customisation.

**Current benchmark: 8.5 / 10 · Target: 10 / 10**

The platform is already highly dynamic in the areas that matter most to tenants
(branding, money format, vocabulary, permissions, theme). What remains is
*structural* dynamism — letting a single institution define its own record shapes
and its own dashboard layout. The hooks for both are noted below.

---

## 1. What is dynamic TODAY

### 1.1 Theme engine — 9/10

| Capability | How it works |
|---|---|
| Light/dark, accent, radius, density, font | `resources/js/Components/ThemeProvider.jsx` writes CSS custom properties onto `<html>` |
| Per-user persistence | DB (`users.theme` JSON) **and** localStorage (`tt.theme`), resolved in a 3-tier precedence (`resolveInitialTheme`) |
| Live, un-saved preview | `applyThemeTokens()` dispatches a `theme:change` event; `useLiveTheme()` lets JS-derived previews follow it in real time |
| Global one-spot toggle | `Components/ThemeToggle.jsx` (paints immediately, then persists to DB + localStorage) |
| Guest/landing coverage | `ThemeProvider` wraps `AuthenticatedLayout`, `GuestLayout` and `Welcome` |

**Remaining gap:** typography is fluid but not per-tenant tokenisable (a tenant
cannot define its own heading scale). See §3.1.

### 1.2 Multi-tenant provisioning — 9/10

- `InstitutionProvisioner` + `InstitutionRegistryController` create a full
  workspace (institution, invite code, hub vendor, subscription) from one action.
- Every tenant-owned model scopes itself automatically via
  `BelongsToInstitution` + `TenantManager`, so a new module is isolated the moment
  it uses the trait.
- Landing-enquiry → approve → provision is a complete zero-code onboarding path.

**Remaining gap:** provisioning is a fixed recipe; a tenant cannot choose which
modules seed on creation.

### 1.3 Currency & number formatting — 9/10

Per-institution symbol, position, precision and separators, stored on
`institutions.currency_settings` and mirrored client-side by
`resources/js/Utils/numberFormatter.js` and server-side by
`app/Support/Money.php`. Both read the same shape, so the UI and exports agree.

### 1.4 Roles & permissions — 8/10

Spatie roles/permissions with a `module` column, granted through
`User::assignInstitutionRole()` (which can never escalate to the global role).
Institution Admins hold an operational subset; the SSA holds everything.

**Remaining gap:** the role matrix is editable, but the *set of permission keys*
is still defined in `RolesAndPermissionsSeeder` — a tenant cannot invent a new
permission for its own custom module.

### 1.5 Membership, subsidies & broadcasts — 8.5/10

- Dynamic rosters (`students` + `departments` + optional `manager_id`), with
  terminology that follows the institution type (Students / Employees / Boarders).
- Configurable funding sources (`subsidy_sources`) and per-institution subsidy
  rules (`percentage`, `apply_mode`).
- **Institution-scoped broadcasts** (`institution_broadcasts`) — audience and
  severity are configurable per send and strictly confined to the owning
  institution.

### 1.6 Rendering — live preview engines — 8/10

`ThemePreviewFrame` is a reusable, theme-aware miniature that reacts to live theme
changes; the Currency Manager, Institution Settings and Theme Customizer all embed
preview surfaces. This is the seed of a general "preview anything before saving"
pattern.

---

## 2. Where dynamism is still MISSING

| Gap | Impact | Why it is hard today |
|---|---|---|
| Custom **field definitions** per institution | A dorm tracks "room no."; a company tracks "employee id". Today those need a migration + code. | Schema is fixed; no EAV/metadata layer. |
| Custom **dashboard widget layout** | Every member sees the same dashboard order. | Dashboard composition is hard-coded per role. |
| Tenant-defined **permissions** | A tenant cannot expose its own custom module. | Permission keys are seeded centrally. |
| Per-tenant **typography scale** | Accent/radius are per-tenant; type scale is global. | Density is a user setting, not an institution one. |

---

## 3. Path to 10/10 — the structural hooks

### 3.1 Hook A — institution field definitions (form builder)

**Add (additive, no rewrite):**

```
institution_field_definitions
  id, institution_id, entity ('student'|'vendor'|'transaction'),
  key, label, type (text|number|date|select|boolean),
  options (json), required, sort_order, is_active
institution_field_values
  id, institution_id, definition_id, subject_type, subject_id, value
```

**The hooks already present:**
- `BelongsToInstitution` gives every new table tenant isolation for free.
- `StudentRequest` / `StoreMemberRequest` are the single validation choke points —
  they can append `Rule`s built from the definitions instead of hard-coded fields.
- The React forms already funnel through `Components/UI/Field.jsx`, so a
  `<DynamicField>` renderer can slot in beside it without touching each page.
- The API already serialises through `app/Http/Resources/*`, so custom values can
  be exposed without changing any controller's shape contract.

### 3.2 Hook B — dashboard widget arrangements

**Add (additive):**

```
dashboard_widgets
  id, institution_id, role, widget_key, sort_order, is_visible, settings (json)
```

**The hooks already present:**
- Member/manager dashboards are `Inertia::render(...)` with a props object — the
  widget *list* can become a prop, and the page renders a `<WidgetGrid>` over it.
- `Components/Analytics/*` (`MetricCard`, `ForecastPanel`, `SubsidyTrackingPanel`)
  are already self-contained widgets; they need registration, not rewriting.
- `StatCard` + the existing responsive grid (`grid-cols-1 sm:grid-cols-2 2xl:...`)
  give the layout primitive.

### 3.3 Hook C — per-tenant typography + module selection

- Promote `density` from a per-user setting to an institution default (one extra
  key on `institutions.theme`; `resolveInitialTheme` already merges institution →
  user).
- Let `InstitutionProvisioner` accept a module list, so provisioning seeds only
  the chosen modules.

### 3.4 Guardrails for any of the above

1. **Tenant isolation is non-negotiable** — every new table uses
   `BelongsToInstitution`; every new reference uses `ApiFormRequest::tenantExists()`.
2. **Never break the `Pages/${name}.jsx` contract** — a new dynamic renderer is a
   component, not a page rename.
3. **Parity first** — additive tables and props only; existing tenants keep exactly
   today's behaviour when they define nothing.
4. **Test the isolation** — each hook ships with a Feature test proving a second
   institution sees nothing (the pattern used by
   `tests/Feature/InstituteAdmin/Broadcasts/InstitutionBroadcastTest.php`).

---

## 4. Summary scorecard

| Dimension | Today | After hooks |
|---|---|---|
| Branding / theme | 9 | 10 |
| Provisioning | 9 | 10 |
| Currency & formatting | 9 | 9 |
| Roles & permissions | 8 | 9 |
| Rosters / subsidies / broadcasts | 8.5 | 9 |
| Live preview rendering | 8 | 9 |
| **Custom fields (form builder)** | 0 | 10 |
| **Dashboard widgets** | 0 | 10 |
| **Overall** | **8.5** | **10** |

The two zero-scoring rows are the whole distance to 10/10 — and both are additive
tables plus a renderer, not a re-architecture.
