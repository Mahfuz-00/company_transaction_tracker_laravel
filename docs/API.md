# API Reference — Mobile JSON API

The mobile application talks to this Laravel backend over a **JSON API** mounted at
`/api`. Authentication is **Laravel Sanctum personal access tokens** (bearer tokens) —
there is **no cookie/session auth** on the mobile surface.

- **Base path:** `/api` (e.g. `https://your-host/api`)
- **Auth header:** `Authorization: Bearer <token>`
- **Content type:** always send `Accept: application/json` and (for bodies)
  `Content-Type: application/json`
- **Money:** returned as a JSON **number** (e.g. `2288.12`). Formatting is the
  client's job; the currency config is served once by `/meta`.
- **Dates:** ISO-8601 (`2026-09-16`, or a full timestamp `2026-09-16T09:00:00+00:00`)
- **Months:** `YYYY-MM` (`2026-09`)
- **Errors:** the standard HTTP codes with a `{ message, errors }` body (§2)

> This document is the authoritative contract for the mobile client. The
> endpoints below are exactly those registered in `routes/api.php`; the shapes
> come from the controllers and the `app/Http/Resources/*` classes they use.
>
> **Scope.** The API provides **full feature parity with the web dashboard** for
> the three tenant roles — **Institution Admin**, **Meal Manager** and **Member**.
> Every route carries the SAME permission gate the web uses, so a role that cannot
> reach a module in the browser cannot reach it here either. The **Software Super
> Admin is web-only** and is refused at every layer (§5).

---

## 1. How authentication works (read this first)

The mobile app never uses cookies or sessions. The flow is:

1. The app sends the user's **email + password** to `POST /api/auth/login` once.
2. The server returns a **plain-text bearer token** (a Sanctum personal access
   token) plus the user object.
3. The app stores the token in secure device storage.
4. The app sends that token on **every** subsequent request:

```http
GET /api/dashboard?month=2026-09 HTTP/1.1
Host: your-host
Accept: application/json
Authorization: Bearer 3|abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789
```

The token is validated by the `auth:sanctum` middleware; inside a controller
`$request->user()` is the token's owner. If the token is missing, malformed, or
expired, the server returns **401**.

### Obtaining a token

`POST /api/auth/login` (see the endpoint reference):

```json
{ "email": "user@example.com", "password": "secret", "device_name": "iPhone 15" }
```

```json
{
  "data": {
    "token": "3|abcdef...",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "QA Dev", "email": "qa@example.com", "roles": ["Member"], "permissions": ["meals.view"] }
  }
}
```

Store `data.token`. On app start, call `GET /api/auth/me` to validate a stored
token and re-hydrate the user; if it returns 401, prompt for login again.

---

## 2. Conventions

| Thing | Rule |
|---|---|
| **Single object envelope** | `{ "data": { ... } }` |
| **Collection envelope** | `{ "data": [ ... ], "meta": { ... } }` |
| **Error envelope** | `{ "message": "...", "errors": { "field": ["..."] } }` |
| **Money** | JSON **number** (float), never a pre-formatted string; `decimal:2` models are cast to `(float)` on the way out |
| **Dates** | ISO-8601 — date-only (`2026-09-16`) for calendar days, full timestamp for audit fields |
| **Month filters** | query param `month=YYYY-MM`; an invalid or absent value falls back to the **current month** |
| **Pagination** | `per_page` query param, capped per endpoint (defaults and caps are listed per endpoint); returned under `meta.pagination` |
| **Failed validation** | HTTP **422** with `message` + `errors` keyed by field |
| **Unauthenticated** | HTTP **401** (`{ "message": "Unauthenticated." }`) |
| **Forbidden** | HTTP **403** — either an **inactive account** (`"This account is inactive."`) or a permission/role denial |
| **Not found / not in tenant** | HTTP **404** — a resource id that is not in the caller's institution fails route-model binding and returns 404, never another workspace's data |
| **Conflict** | HTTP **409** — the request is valid but the current state blocks it (e.g. deleting a member with history) |
| **Throttled** | HTTP **429** — rate limit exceeded (see §3) |

### The month filter

Every list/report endpoint accepts `?month=YYYY-MM`. `GET /api/dashboard` with no
`month` reports on the current month. The response always echoes the resolved
month back under `data.month` / `meta.month` so the client never has to guess
which period a payload covers.

### Money and formatting

Amounts are exact at rest (`decimal:2` columns) but travel as JSON numbers.
Currency metadata (symbol, position, separators, precision, abbreviation rules)
is served **once** by `GET /api/meta` — render it locally and never hard-code a
currency symbol.

### Dates

- Calendar/period fields (`date`, `join_date`, `period_month`): `YYYY-MM-DD` or
  `YYYY-MM` respectively.
- Audit/activity fields (`created_at` on deposits, subsidies and the dashboard
  activity feed): full ISO-8601 timestamp.

---

## 3. Rate limiting

Every `/api/*` route is throttled. Two named limiters are registered in
`app/Providers/AppServiceProvider.php` and applied as follows:

| Limiter | Where it applies | Limit | Key |
|---|---|---|---|
| `api` | **every** `/api` route (attached group-wide in `bootstrap/app.php` via `throttle:api`) | **60 requests / minute** | authenticated **user id**, falling back to client **IP** for public routes |
| `api-login` | `POST /api/auth/login` and `POST /api/auth/register` only (strict, on top of the baseline) | **5 requests / minute** | lower-cased **email** + **IP** |

**Why two?** The baseline `api` limiter stops one abusive client from exhausting
the API. The stricter `api-login` limiter slows credential guessing **without**
letting one attacker lock out a different email from the same address (the key
includes the email, not just the IP).

### The 429 response

When a limit is exceeded the server returns HTTP **429** with a `Retry-After`
header (seconds) and Laravel's default body:

```json
{ "message": "Too Many Attempts." }
```

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 42
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
```

The client should back off for `Retry-After` seconds. On the login screen a 429
means "slow down / try again shortly" — it is distinct from the 422 used for
wrong credentials.

---

## 4. Token lifetime

Tokens are **not** eternal. From `config/sanctum.php`:

- **Expiration: 30 days** (`SANCTUM_EXPIRATION`, expressed in **minutes**, default
  `43200`). This is a deliberate security change from Sanctum's framework default
  of `null` (never expire). Set `SANCTUM_EXPIRATION=null` in the environment to
  opt back into non-expiring tokens — **not recommended**.
- After expiry the token fails authentication and every protected call returns
  **401**; the client must log in again.
- **`POST /api/auth/logout` revokes only the calling token**, not every token the
  user holds. Signing out on one device leaves the user's other devices signed in.
- A user can mint additional tokens per recognised device with
  `POST /api/auth/devices` (each also carries the 30-day lifetime from its issue
  time).

**Client guidance:** treat a 401 as "token gone" — clear stored credentials and
return to the login screen. Do not retry the same token in a loop.

---

## 5. Multi-tenant isolation

The system is multi-tenant: every user belongs to **one institution**, and every
tenant-owned record (members, deposits, meal entries, subsidies, departments,
vendors, transactions, …) carries an `institution_id`. Isolation is enforced in
two independent layers:

1. **Automatic query scoping.** Tenant-owned models use the
   `BelongsToInstitution` trait, whose global scope filters **every** query to the
   active institution (resolved by `App\Support\TenantManager`). A controller
   cannot "forget" to scope — it is the default.

2. **Tenant-scoped validation.** The `app/Http/Requests/Api/*` Form Requests use
   `ApiFormRequest::tenantExists()`, which restricts each foreign-key `exists`
   rule to the caller's institution. This closed a real hole: the old rules used
   table-wide `exists:students,id`, so a caller in Institution A could name a
   member id from Institution B. Now that id fails validation.

### What the client should expect

- **Body references** (`student_id`, `department_id`, `manager_id`) from another
  institution → **422** (a normal validation error on that field).
- **Route-bound resources** (`{member}` in `/members/{member}`) from another
  institution → the scoped model fails to bind → **404**. The caller never sees
  another workspace's data.
- Every list/response is confined to the caller's institution, including the
  month totals and the reports.

### Software Super Admin — excluded from the mobile API

A **Software Super Admin (SSA)** is a platform-level role. In the **web** app an
SSA uses a session-scoped "switch into a workspace" control. The **mobile API has
no tenant-switch**, and there is no session — so an SSA bearer token would resolve
to a **GLOBAL, unscoped context** (`TenantManager::resolveTenantId()` returns
`null`), meaning tenant-owned queries would run **without** an institution filter
and an SSA token would see **every** institution's data.

**The mobile API therefore refuses SSA accounts outright** (three layers, see
[FLUTTER_MOBILE_APP.md §1.1](FLUTTER_MOBILE_APP.md)):

- `POST /api/auth/login` returns **403**
  `{ "message": "Platform administrators must use the web console." }` and mints
  **no** token for an SSA account.
- `GET /api/auth/me` returns the same **403** if a stored token resolves to an SSA,
  so a token issued before this rule cannot be reused.
- Every other protected endpoint is reached only through a token that passed the
  two guards above, so an SSA can never hold a working mobile session.

**What a normal tenant client expects:** an SSA is simply not a mobile user. There
is no global context to render, and `GET /api/meta` never returns a null institution
for a legitimate mobile session.

---

## 6. Endpoint reference

**61 endpoints total.** Grouped by area. "Auth" indicates whether
`Authorization: Bearer` is required; **Permission** is the route-level gate
(`permission:*` middleware) — a request without it gets **403**.

> **The mobile app serves three roles only:** Institution Admin, Meal Manager and
> Member. A **Software Super Admin** is refused at login, at `/auth/me`, and by the
> `mobile.not-ssa` middleware on **every** authenticated route (§5).

### 6.0 Quick index

#### Public

| # | Method | Path | Auth |
|---|---|---|---|
| 1 | POST | `/api/auth/register` | public |
| 2 | POST | `/api/auth/login` | public |
| 3 | GET | `/api/meta` | public |

#### Session & identity

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 4 | GET | `/api/auth/me` | ✅ | — |
| 5 | POST | `/api/auth/logout` | ✅ | — |
| 6 | POST | `/api/auth/devices` | ✅ | — |
| 7 | PATCH | `/api/auth/profile` | ✅ | — |
| 8 | PUT | `/api/auth/password` | ✅ | — |

#### My account (self-service — any role)

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 9 | GET | `/api/me/dashboard` | ✅ | — |
| 10 | GET | `/api/me/meals` | ✅ | — |
| 11 | GET | `/api/me/deposits` | ✅ | — |
| 12 | GET | `/api/me/analytics` | ✅ | — |
| 13 | PUT | `/api/me/theme` | ✅ | — |

#### Notifications

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 14 | GET | `/api/notifications` | ✅ | — |
| 15 | GET | `/api/notifications/latest` | ✅ | — |
| 16 | POST | `/api/notifications/{id}/read` | ✅ | — |
| 17 | POST | `/api/notifications/read-all` | ✅ | — |
| 18 | DELETE | `/api/notifications/{id}` | ✅ | — |
| 19 | POST | `/api/notifications/announce` | ✅ | `notifications.announce` (Admin) |

#### Dashboard & members

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 20 | GET | `/api/dashboard` | ✅ | — |
| 21 | GET | `/api/members` | ✅ | `students.view` |
| 22 | GET | `/api/members/{member}` | ✅ | `students.view` |
| 23 | POST | `/api/members` | ✅ | `students.manage` |
| 24 | PATCH | `/api/members/{member}` | ✅ | `students.manage` |
| 25 | DELETE | `/api/members/{member}` | ✅ | `students.manage` |

#### Money in / out

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 26 | GET | `/api/deposits` | ✅ | `meals.deposit` |
| 27 | POST | `/api/deposits` | ✅ | `meals.deposit` |
| 28 | GET | `/api/deposits/export` | ✅ | `meals.deposit` |
| 29 | GET | `/api/expenses` | ✅ | `meals.expense` |
| 30 | POST | `/api/expenses` | ✅ | `meals.expense` |

#### Meals

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 31 | GET | `/api/meals` | ✅ | — |
| 32 | GET | `/api/meals/day` | ✅ | `meals.view` |
| 33 | POST | `/api/meals/day` | ✅ | `meals.entry` |

#### Subsidies

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 34 | GET | `/api/subsidies` | ✅ | `subsidies.view` |
| 35 | POST | `/api/subsidies` | ✅ | `subsidies.manage` |
| 36 | GET | `/api/subsidies/sources` | ✅ | `subsidies.view` |

#### Vendors & departments

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 37 | GET | `/api/vendors` | ✅ | `vendors.view` |
| 38 | POST | `/api/vendors` | ✅ | `vendors.manage` |
| 39 | GET | `/api/departments` | ✅ | `departments.view` |
| 40 | POST | `/api/departments` | ✅ | `departments.manage` |

#### Claims

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 41 | GET | `/api/claims` | ✅ | — (own only) |
| 42 | POST | `/api/claims` | ✅ | — (own only) |
| 43 | GET | `/api/claims/review` | ✅ | `claims.review` |
| 44 | PATCH | `/api/claims/{id}/approve` | ✅ | `claims.review` |
| 45 | PATCH | `/api/claims/{id}/reject` | ✅ | `claims.review` |

#### Member payments

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 46 | GET | `/api/me/payments` | ✅ | — (own only) |
| 47 | POST | `/api/me/payments` | ✅ | — (own only) |
| 48 | GET | `/api/member-payments` | ✅ | `meals.deposit` |
| 49 | PATCH | `/api/member-payments/{id}/approve` | ✅ | `meals.deposit` |
| 50 | PATCH | `/api/member-payments/{id}/reject` | ✅ | `meals.deposit` |

#### Meal menus & voting

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 51 | GET | `/api/menus` | ✅ | — |
| 52 | GET | `/api/menus/{menu}` | ✅ | — |
| 53 | POST | `/api/menus/{menu}/vote` | ✅ | — (own vote) |
| 54 | POST | `/api/menus` | ✅ | `meals.reports` |
| 55 | PATCH | `/api/menus/{menu}/status` | ✅ | `meals.reports` |

#### Workspace settings

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 56 | GET | `/api/settings/institution` | ✅ | `institution.view` |
| 57 | PUT | `/api/settings/institution` | ✅ | `institution.manage` |
| 58 | GET | `/api/settings/subsidy-sources` | ✅ | `subsidies.view` |

#### Reports

| # | Method | Path | Auth | Permission |
|---|---|---|---|---|
| 59 | GET | `/api/reports/meal` | ✅ | `meals.reports` |
| 60 | GET | `/api/reports/analytics` | ✅ | `transactions.view` |
| 61 | GET | `/api/reports/forecast` | ✅ | `meals.reports` |
| 62 | GET | `/api/reports/per-meal-rate` | ✅ | `meals.reports` |

> **Counting note:** the index above lists 62 rows; three are the public routes and
> one (`/api/reports/per-meal-rate`) is an alias of the report family, so the
> route-level total mounts at **61 authenticated + 3 public** distinct entries.

---

### 6.1 Auth & identity

#### `POST /api/auth/register` — self-registration *(public)*

Registers a new account and returns a token immediately. The account is **always**
assigned the **Member** role (there is no `role` field accepted — never trust a
client for a role).

The account's institution is resolved from the **`invite_code`** — the same
tenant-mapping key the web sign-up form uses. The code is resolved **before**
anything is written, so an invalid code fails cleanly (**422**) instead of creating
an orphaned account.

**Throttle:** `api-login` (5/min per email+IP) **and** baseline `api` (60/min).

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | max 255 |
| `email` | string | ✅ | valid email, max 255, unique |
| `password` | string | ✅ | must be **confirmed** (send `password_confirmation`) |
| `invite_code` | string | ✅ | max 24; must resolve to an **active** institution |
| `device_name` | string | ✕ | max 120; used to label the token |

```json
{ "name": "New Member", "email": "new@example.com", "password": "secret123", "password_confirmation": "secret123", "invite_code": "AB12CD34", "device_name": "Pixel 8" }
```

**201**
```json
{
  "data": {
    "token": "5|abcdef...",
    "token_type": "Bearer",
    "user": { "id": 7, "name": "New Member", "email": "new@example.com", "roles": ["Member"], "permissions": [], "is_super_admin": false, "institution_id": 1, "status": "active" }
  }
}
```

**Failures**

| Code | Body | When |
|---|---|---|
| 422 | `{ "message": "...", "errors": { "invite_code": ["That invitation code is not valid. Ask your institution admin for the correct code."] } }` | the code does not resolve to an institution |
| 422 | `{ "message": "...", "errors": { "invite_code": ["That institution is not currently accepting new members."] } }` | the resolved institution is inactive |
| 422 | `{ "message": "...", "errors": { "email": [...] } }` | the email is already registered |

---

#### `POST /api/auth/login` — exchange credentials for a token *(public)*

**Throttle:** `api-login` (5/min per email+IP) **and** baseline `api`.

| Field | Type | Required | Notes |
|---|---|---|---|
| `email` | string | ✅ | valid email |
| `password` | string | ✅ | |
| `device_name` | string | ✕ | max 120; defaults to `"mobile"` |

```json
{ "email": "user@example.com", "password": "secret", "device_name": "iPhone 15" }
```

**200**
```json
{
  "data": {
    "token": "3|abcdef...",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "QA Dev", "email": "qa@example.com", "phone": null, "designation": null, "avatar_url": null, "status": "active", "institution_id": 1, "roles": ["Institution Admin"], "permissions": ["meals.view", "meals.entry", "..."], "is_super_admin": false, "last_login_at": "2026-09-16T09:00:00+00:00" }
  }
}
```

**Failures**

| Code | Body | When |
|---|---|---|
| 422 | `{ "message": "The provided credentials are incorrect." }` | unknown email **or** wrong password (deliberately identical, so the endpoint cannot be used to enumerate emails) |
| 403 | `{ "message": "This account is inactive." }` | credentials are correct but the account is not active |
| 429 | `{ "message": "Too Many Attempts." }` | more than 5 attempts/min for that email from that IP |

---

#### `GET /api/auth/me` — the current user *(auth)*

Returns the token owner. Use on app start to validate a stored token and hydrate
the UI. The `user` object is the shared `UserResource` shape (see below).

**200**
```json
{ "data": { "id": 1, "name": "QA Dev", "email": "qa@example.com", "roles": ["Institution Admin"], "permissions": ["..."], "is_super_admin": false, "institution_id": 1, "status": "active", "last_login_at": "2026-09-16T09:00:00+00:00" } }
```

---

#### `POST /api/auth/logout` — revoke the calling token *(auth)*

Revokes **only** the token used to make the call; other devices stay signed in.

**200**
```json
{ "message": "Signed out." }
```

---

#### `POST /api/auth/devices` — mint an extra device token *(auth)*

Issues another personal access token for a recognised device, **without**
invalidating the current one. Useful for multi-device support.

| Field | Type | Required | Notes |
|---|---|---|---|
| `device_name` | string | ✅ | max 120 |

**201**
```json
{ "data": { "token": "6|abcdef...", "token_type": "Bearer" } }
```

---

#### `PATCH /api/auth/profile` — update your own profile *(auth)*

Update the signed-in user's own profile. Send as **`multipart/form-data`** when
uploading an `avatar`; otherwise JSON is fine.

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | max 255 |
| `phone` | string | ✕ | max 30 |
| `designation` | string | ✕ | max 120 (job title / role label) |
| `avatar` | file | ✕ | image, max **2048 KB** (2 MB). Stored on the `public` disk under `avatars/` |

**200** — the refreshed user:
```json
{ "data": { "id": 1, "name": "QA Dev", "email": "qa@example.com", "phone": "01700000000", "designation": "Manager", "avatar_url": "https://your-host/storage/avatars/abc.jpg?v=1726490000", "roles": ["Member"], "permissions": [], "is_super_admin": false, "institution_id": 1, "status": "active", "last_login_at": "2026-09-16T09:00:00+00:00" } }
```

---

#### `UserResource` — the canonical user shape

Every auth endpoint (login, register, me, profile) returns the user through the
same `App\Http\Resources\UserResource`, so the wire format is identical everywhere:

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `name` | string | |
| `email` | string | |
| `phone` | string \| null | |
| `designation` | string \| null | |
| `avatar_url` | string \| null | computed public URL with a cache-busting `?v=` |
| `status` | string | e.g. `active` / `inactive` |
| `institution_id` | int \| null | the user's home institution |
| `roles` | string[] | e.g. `["Institution Admin"]` |
| `permissions` | string[] | flattened permission names — use these to hide UI the user cannot use |
| `is_super_admin` | bool | true for a Software Super Admin (global reach) |
| `last_login_at` | string \| null | ISO-8601 timestamp |

---

### 6.2 Meta

#### `GET /api/meta` — server + contract metadata *(public)*

Everything the client needs to render itself correctly. Call once at startup and
cache. Never hard-code terminology or currency — read them from here.

```json
{
  "data": {
    "app_name": "Laravel",
    "institution": {
      "id": 1,
      "name": "Acme Ltd",
      "subtitle": "Staff cafeteria",
      "type": "company",
      "type_label": "Company / Corporate Office",
      "logo_url": "https://your-host/storage/branding/logo.png?v=1726490000",
      "terms": { "member": "Employee", "members": "Employees", "department": "Team", "departments": "Teams" },
      "theme": { "accent": "indigo", "mode": "light", "radius": "lg", "density": "comfortable" }
    },
    "currency": {
      "symbol": "৳",
      "symbol_position": "before",
      "decimal_separator": ".",
      "thousands_separator": ",",
      "decimal_precision": 2,
      "numbering_system": "short",
      "abbreviations": true,
      "abbreviation_threshold": 1000
    },
    "subsidy_modes": {
      "pool": "Into the common pool",
      "per_member": "Split per active member",
      "credit_behind": "Reserve (applied after member funds)"
    },
    "subsidy_sources": {
      "university_authority": "University Authority",
      "company_management": "Company Management",
      "college_administration": "College Administration",
      "government_grant": "Government Grant",
      "donation": "Donation",
      "other": "Other"
    },
    "deposit_kinds": {
      "personal": "Personal deposit",
      "subsidy": "Institutional subsidy",
      "credit": "Credit adjustment"
    },
    "api_version": "1.0.0"
  }
}
```

Notes:

- `institution` is `null` when no tenant is resolvable (e.g. a fresh install before
  an institution exists). An **SSA token never reaches this endpoint** — it is
  refused with 403 at login and at `/auth/me` (see §5).
- **Render with `terms`, never hard-coded nouns.** A company says "Employees", a
  dorm says "Students". The keys are stable; the values change per institution.
- `subsidy_sources` here is the **fixed platform list** (enum keys → labels).
  For the institution's **configured** funding sources (with default shares), use
  `GET /api/subsidies/sources`.

---

### 6.3 Dashboard

#### `GET /api/dashboard?month=YYYY-MM` *(auth)*

The month summary in one call — the payload the home screen loads.

Query: `month` (optional; defaults to the current month).

```json
{
  "data": {
    "month": "2026-09",
    "month_label": "September 2026",
    "summary": {
      "month": "2026-09",
      "label": "September 2026",
      "meals": 97,
      "expenses": 2288.12,
      "deposits": 1712.25,
      "subsidies": 0,
      "per_meal_rate": 23.5889,
      "meal_cost": 2288.12,
      "subsidy_coverage_pct": 0,
      "member_funded_pct": 74.83,
      "member_shortfall": 575.87,
      "pool_balance": -575.87,
      "members": 4,
      "meals_per_member": 24.3,
      "cost_per_member": 572.03,
      "daily_meals": 3.2,
      "daily_cost": 76.27
    },
    "members": { "total": 5, "active": 4, "with_dues": 2, "total_dues": 575.87 },
    "top_dues": [
      { "id": 3, "name": "Farhan", "roll": "CS-045", "balance": -210.5 }
    ],
    "recent_activity": [
      { "id": 12, "type": "in", "label": "Deposit", "item": "Meal Deposit for Farhan", "amount": 3000, "category": "Meal Deposit", "member": "Farhan", "recorded_by": "QA Dev", "date": "2026-09-16T09:00:00+00:00" }
    ]
  }
}
```

- `summary` is the shared **month snapshot** (the same object used by the
  reports). `pool_balance` = deposits + subsidies − expenses.
- `members.total_dues` is the sum of absolute outstanding balances.
- `top_dues` lists up to **10** members who owe the pool, biggest debt first.
- `recent_activity` lists up to **10** latest ledger transactions
  (`type` is `"in"` = Deposit or `"out"` = Expense).

---

### 6.4 Members

Internally a "member" is a `students` row; the API calls them **members** to match
the product vocabulary. Meals and balances are **month-scoped**, priced at that
month's per-meal rate.

#### `GET /api/members?month=&search=&status=&per_page=` *(auth)*

Paginated roster with each member's figures for the selected month.

| Query | Type | Notes |
|---|---|---|
| `month` | `YYYY-MM` | defaults to the current month |
| `search` | string | matches `name` **or** `roll` (LIKE) |
| `status` | string | e.g. `active` / `inactive` |
| `per_page` | int | default **25**, max **100** |

```json
{
  "data": [
    { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045", "department": "CSE", "status": "active", "has_account": true, "month_meals": 24, "breakfast": 8, "lunch": 9, "dinner": 7, "meal_cost": 566.13, "deposited": 600, "subsidy_share": 0, "balance": 33.87, "is_due": false }
  ],
  "meta": {
    "month": "2026-09",
    "per_meal_rate": 23.5889,
    "departments": [ { "id": 1, "name": "CSE" }, { "id": 2, "name": "EEE" } ],
    "pagination": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 4 }
  }
}
```

Row fields: `has_account` = the member has their own login (has been invited);
`balance` = `deposited − meal_cost` (negative means they owe); `is_due` = `balance < 0`.
`meta.per_meal_rate` and `meta.departments` are provided so the list screen can
render without extra calls.

---

#### `GET /api/members/{member}?month=` *(auth)*

Full member detail for the month, with recent history.

| Query | Type | Notes |
|---|---|---|
| `month` | `YYYY-MM` | defaults to the current month |

```json
{
  "data": {
    "id": 1,
    "name": "Farhan Hossain",
    "roll": "CS-2021-045",
    "department": "CSE",
    "status": "active",
    "managed_by": "QA Dev",
    "account": { "id": 4, "name": "Farhan Hossain", "email": "farhan@example.com" },
    "month": "2026-09",
    "figures": { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045", "department": "CSE", "status": "active", "has_account": true, "breakfast": 8, "lunch": 9, "dinner": 7, "meals": 24, "meal_cost": 566.13, "deposited": 600, "subsidy_share": 0, "balance": 33.87, "is_due": false },
    "recent_deposits": [ { "id": 9, "amount": 3000, "kind": "personal", "date": "2026-09-10T09:00:00+00:00" } ],
    "recent_meals": [ { "date": "2026-09-16", "breakfast": 1, "lunch": 1, "dinner": 0, "total": 2 } ]
  }
}
```

- `account` is `null` when the member has no login yet.
- `figures` is the member's month breakdown (same fields as a roster row, plus `meals`).
- `recent_deposits` — up to **20** latest deposits for this member.
- `recent_meals` — up to **30** latest meal entries by date.
- A `{member}` id from another institution returns **404** (route-model binding is
  tenant-scoped).

---

#### `POST /api/members` — create a member *(auth)*

Validated by `StoreMemberRequest`. `department_id` / `manager_id` are **scoped to
the caller's institution** — a foreign id is a **422**.

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | max 255 |
| `roll` | string | ✕ | max 100 |
| `department_id` | int | ✕ | must exist in **your** institution |
| `manager_id` | int | ✕ | must be a user in **your** institution |
| `join_date` | date | ✕ | `YYYY-MM-DD` |
| `status` | string | ✅ | `active` \| `inactive` |

```json
{ "name": "New Member", "roll": "CS-2026-101", "department_id": 1, "manager_id": 4, "join_date": "2026-09-01", "status": "active" }
```

**201**
```json
{ "data": { "id": 12, "name": "New Member" } }
```

---

#### `PATCH /api/members/{member}` — update a member *(auth)*

Same field set and tenant-scoped references as creation (validated by
`UpdateMemberRequest`, which extends `StoreMemberRequest`). The `{member}` is
resolved through the tenant-scoped model, so a foreign id returns **404**.

**200**
```json
{ "data": { "id": 12, "name": "Renamed Member" } }
```

---

#### `DELETE /api/members/{member}` — remove a member *(auth)*

Deletion is refused if the member has **any meal or deposit history** (deleting
them would silently rewrite historical pool totals) — deactivate them instead.

**200**
```json
{ "message": "Member removed." }
```

**409**
```json
{ "message": "This member has meal or deposit history and cannot be deleted." }
```

---

### 6.5 Deposits (Cash In)

A deposit is recorded in the module table **and** mirrored into the shared ledger
in one database transaction, so the two can never disagree. `amount` is money
(JSON number).

#### `GET /api/deposits?month=&member=&kind=&per_page=` *(auth)*

| Query | Type | Notes |
|---|---|---|
| `month` | `YYYY-MM` | defaults to the current month |
| `member` | int | filter by member (`student_id`) |
| `kind` | string | `personal` \| `subsidy` \| `credit` |
| `per_page` | int | default **25**, max **100** |

```json
{
  "data": [
    { "id": 9, "member": "Farhan", "member_id": 1, "roll": "CS-045", "amount": 3000, "kind": "personal", "payment_method": "bKash", "recorded_by": "QA Dev", "notes": null, "date": "2026-09-10T09:00:00+00:00" }
  ],
  "meta": {
    "month": "2026-09",
    "totals": { "all": 4712.25, "personal": 4712.25, "subsidy": 0 },
    "kinds": { "personal": "Personal deposit", "subsidy": "Institutional subsidy", "credit": "Credit adjustment" },
    "members": [ { "id": 1, "name": "Farhan", "roll": "CS-045" } ],
    "pagination": { "current_page": 1, "last_page": 1, "total": 4 }
  }
}
```

`meta.totals` is computed over the **whole month**, not just the returned page, so
the header figures do not change as the user scrolls. `meta.members` is the active
roster for the "record deposit" picker.

---

#### `POST /api/deposits` — record a deposit *(auth)*

Validated by `StoreDepositRequest` (`student_id` scoped to your institution).
Also writes a matching Cash-In ledger transaction.

| Field | Type | Required | Notes |
|---|---|---|---|
| `student_id` | int | ✅ | member in **your** institution |
| `amount` | number | ✅ | `> 0` (min `0.01`) |
| `payment_method` | string | ✕ | max 60 (Cash, bKash, …) |
| `kind` | string | ✕ | `personal` (default) \| `subsidy` \| `credit` |
| `notes` | string | ✕ | max 1000 |

```json
{ "student_id": 1, "amount": 3000, "payment_method": "bKash", "kind": "personal", "notes": "Rent share" }
```

**201**
```json
{ "data": { "id": 21, "amount": 3000 }, "message": "Deposit recorded." }
```

---

#### `GET /api/deposits/export?month=` *(auth)*

A flat, month-scoped JSON projection for **client-side export**. Its row shape
differs from the index **on purpose** (no `id`, date-only). A server-side file
download is also available via the web export route.

```json
{
  "data": [
    { "date": "2026-09-10", "member": "Farhan", "roll": "CS-045", "kind": "personal", "amount": 3000, "payment_method": "bKash", "notes": null }
  ],
  "meta": { "month": "2026-09", "total": 4712.25, "count": 4 }
}
```

---

### 6.6 Meals

A "meal entry" is one member's breakfast/lunch/dinner counts for one date.

#### `GET /api/meals?month=&member=&per_page=` *(auth)*

Historical meal entries for a month, plus month totals.

| Query | Type | Notes |
|---|---|---|
| `month` | `YYYY-MM` | defaults to the current month |
| `member` | int | filter by member (`student_id`) |
| `per_page` | int | default **50**, max **200** |

```json
{
  "data": [
    { "id": 5, "member": "Farhan", "member_id": 1, "roll": "CS-045", "date": "2026-09-16", "breakfast": 1, "lunch": 1, "dinner": 0, "total": 2 }
  ],
  "meta": {
    "month": "2026-09",
    "totals": { "breakfast": 4, "lunch": 4, "dinner": 3, "total": 11 },
    "pagination": { "current_page": 1, "last_page": 1, "total": 97 }
  }
}
```

`total` is computed server-side (`breakfast + lunch + dinner`) so the client never
re-adds columns.

---

#### `GET /api/meals/day?date=YYYY-MM-DD` — the day grid *(auth)*

Every **active** member with whatever is already recorded for the requested date,
plus the day's running totals. This is what the "record meals" screen loads.

| Query | Type | Notes |
|---|---|---|
| `date` | `YYYY-MM-DD` | defaults to today |

```json
{
  "data": {
    "date": "2026-09-16",
    "members": [
      { "id": 1, "name": "Farhan", "roll": "CS-045", "breakfast": 1, "lunch": 1, "dinner": 0 }
    ],
    "totals": { "breakfast": 4, "lunch": 4, "dinner": 3, "total": 11 }
  }
}
```

---

#### `POST /api/meals/day` — save a whole day *(auth)*

Save the entire day's grid in **one** request. Validated by `StoreMealDayRequest`;
each `entries.*.student_id` is **scoped to your institution** (a crafted foreign id
is rejected with **422** before anything is written).

| Field | Type | Required | Notes |
|---|---|---|---|
| `date` | date | ✅ | `YYYY-MM-DD` |
| `entries` | array | ✅ | one row per member |
| `entries[].student_id` | int | ✅ | member in **your** institution |
| `entries[].breakfast` | int | ✕ | 0–10 |
| `entries[].lunch` | int | ✕ | 0–10 |
| `entries[].dinner` | int | ✕ | 0–10 |

```json
{
  "date": "2026-09-16",
  "entries": [
    { "student_id": 1, "breakfast": 1, "lunch": 1, "dinner": 0 },
    { "student_id": 2, "breakfast": 0, "lunch": 1, "dinner": 1 }
  ]
}
```

**200**
```json
{
  "data": { "saved": 2, "date": "2026-09-16", "totals": { "breakfast": 1, "lunch": 2, "dinner": 1, "total": 4 } },
  "message": "Meal entries saved for 2 member(s)."
}
```

> **Zero rows are deleted, not stored.** A row with all three meals at `0` clears
> that member's entry for the date (so "no entry" and "zero meals" are the same
> state). `saved` counts only the rows that were actually written (non-zero).

---

### 6.7 Subsidies (institutional funding)

Subsidy money is **never mixed** into a member's own deposited funds — it is a
separate pool tracked apart from deposits. Each subsidy also writes a matching
ledger transaction.

#### `GET /api/subsidies?month=&source=&per_page=` *(auth)*

| Query | Type | Notes |
|---|---|---|
| `month` | `YYYY-MM` | defaults to the current month |
| `source` | string | a source key |
| `per_page` | int | default **25**, max **100** |

```json
{
  "data": [
    { "id": 4, "source": "university_authority", "source_name": "University Authority", "amount": 5000, "percentage": 20, "apply_mode": "pool", "period_month": "2026-09", "status": "active", "scope": "Whole institution", "recorded_by": "QA Dev", "notes": null, "date": "2026-09-05T09:00:00+00:00" }
  ],
  "meta": {
    "month": "2026-09",
    "total": 5000,
    "source_totals": [
      { "key": "university_authority", "total": 5000, "entries": 1, "actual_percentage": 100 }
    ],
    "apply_modes": { "pool": "Into the common pool", "per_member": "Split per active member", "credit_behind": "Reserve (applied after member funds)" },
    "pagination": { "current_page": 1, "last_page": 1, "total": 1 }
  }
}
```

- `meta.total` counts only **active** subsidies for the month.
- `scope` resolves to the department name, else the member name, else
  `"Whole institution"`.
- `meta.source_totals[].actual_percentage` is that source's **real** share of all
  subsidy money that month (a configured `percentage` is the *intended* share).

---

#### `POST /api/subsidies` — record a subsidy *(auth)*

Validated by `StoreSubsidyRequest`. Optional `department_id` / `student_id` targets
are **scoped to your institution**.

| Field | Type | Required | Notes |
|---|---|---|---|
| `source` | string | ✅ | a source key (or your own label), max 60 |
| `source_label` | string | ✕ | free-text funder name, max 120 |
| `amount` | number | ✅ | `> 0` (min `0.01`) |
| `percentage` | number | ✕ | `0`–`100` |
| `apply_mode` | string | ✅ | `pool` \| `per_member` \| `credit_behind` |
| `department_id` | int | ✕ | in **your** institution |
| `student_id` | int | ✕ | in **your** institution |
| `period_month` | `YYYY-MM` | ✕ | defaults to the current month |
| `notes` | string | ✕ | max 1000 |

```json
{ "source": "university_authority", "amount": 5000, "percentage": 20, "apply_mode": "pool", "period_month": "2026-09" }
```

**201**
```json
{ "data": { "id": 4, "amount": 5000 }, "message": "Subsidy recorded." }
```

---

#### `GET /api/subsidies/sources` — configured funding sources *(auth)*

The funding sources configured for this institution (managed in the admin's
**Settings → Subsidy Sources**), plus the shared platform defaults. Auto-seeds
defaults on first use.

```json
{
  "data": [
    { "id": 1, "name": "University Authority", "key": "university_authority", "percentage": 20, "description": "Annual grant" },
    { "id": 2, "name": "Alumni Donation", "key": "donation", "percentage": null, "description": null }
  ]
}
```

`percentage` is the source's **default** share (`null` when unset). All returned
sources are active.

---

## 6.7b My account (member self-service)

Every route here resolves the member from the **token**, so no member id is ever
accepted — cross-member reads are structurally impossible. Open to any
authenticated non-SSA user.

#### `GET /api/me/dashboard?month=` *(auth)*

The member's own month summary plus lifetime totals. Mirrors the web
`Member/Dashboard` page.

```json
{
  "data": {
    "has_member_record": true,
    "month": "2026-09",
    "member": { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045", "status": "active", "department": "Computer Science", "manager": "Rakib Hasan", "institution": "North South University Hall" },
    "summary": {
      "balance": 33.87, "is_due": false,
      "month_meals": 24, "month_breakfast": 8, "month_lunch": 9, "month_dinner": 7,
      "month_meal_cost": 566.13, "month_deposited": 600, "subsidy_share": 0,
      "lifetime_deposits": 4712.25, "lifetime_meals": 148,
      "lifetime_meal_cost_estimate": 3491.16, "cost_per_meal": 23.5889
    },
    "recent_entries": [ { "date": "2026-09-16", "breakfast": 1, "lunch": 1, "dinner": 0, "total": 2 } ],
    "recent_deposits": [ { "id": 9, "amount": 3000, "kind": "personal", "payment_method": "bKash", "date": "2026-09-10T09:00:00+00:00" } ],
    "claims_pending": 1
  }
}
```

- `has_member_record: false` means the login is not yet linked to a roster row —
the UI shows a clear empty state, and no `404` is raised.
- `lifetime_meal_cost_estimate` is an **estimate** priced at the CURRENT rate, not
exact historical pricing. Label it as such.

#### `GET /api/me/meals?month=&per_page=` *(auth)*

The member's own meal entries, with month totals computed over **all** rows (not
just the page).

```json
{
  "data": [ { "id": 5, "date": "2026-09-16", "breakfast": 1, "lunch": 1, "dinner": 0, "total": 2 } ],
  "meta": { "month": "2026-09", "has_member_record": true, "totals": { "breakfast": 4, "lunch": 4, "dinner": 3, "total": 11 }, "pagination": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 24 } }
}
```

#### `GET /api/me/deposits?month=&per_page=` *(auth)*

```json
{
  "data": [ { "id": 9, "amount": 3000, "kind": "personal", "kind_label": "Personal deposit", "payment_method": "bKash", "notes": null, "date": "2026-09-10T09:00:00+00:00" } ],
  "meta": { "month": "2026-09", "has_member_record": true, "month_total": 600, "lifetime_total": 4712.25, "pagination": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 2 } }
}
```

#### `GET /api/me/analytics?months=` *(auth)*

The member's personal month-by-month trend (default 6 months, max 24), newest
first so it maps straight onto the chart's x-axis.

```json
{
  "data": {
    "has_member_record": true,
    "member": { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045" },
    "months": [ { "month": "2026-09", "label": "Sep 2026", "meals": 24, "breakfast": 8, "lunch": 9, "dinner": 7, "meal_cost": 566.13, "deposited": 600, "balance": 33.87, "per_meal_rate": 23.5889, "cost_per_meal": 23.59 } ]
  }
}
```

#### `PUT /api/me/theme` *(auth)*

Set the caller's OWN theme preference. Fields are **merged**, so the app may send
only what the user changed.

| Field | Type | Required | Notes |
|---|---|---|---|
| `mode` | string | ✕ | `light` \| `dark` \| `system` |
| `accent` | string | ✕ | accent key, max 30 |
| `density` | string | ✕ | e.g. `comfortable` \| `compact` |

```json
{ "mode": "dark" }
```

**200** -> `{ "data": { "theme": { "mode": "dark" } }, "message": "Theme updated." }`

#### `PUT /api/auth/password` — change your own password *(auth)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `current_password` | string | ✅ | verified against the stored hash |
| `password` | string | ✅ | must be **confirmed** (`password_confirmation`) |

**200** -> `{ "message": "Password updated. Other devices were signed out." }`

- A wrong current password is a **422** with `errors.current_password`.
- On success every **other** device token is revoked; the calling token survives,
so the user is not ejected from the app they just used to make the change.

---

## 6.7c Notifications

A user only ever sees their OWN notifications (the notifiable morph scopes it).

#### `GET /api/notifications?per_page=` *(auth)*

```json
{
  "data": [ { "id": "9f...", "kind": "deposit_verified", "title": "Payment verified", "body": "Your payment of 3000 was approved.", "url": "/deposits/9", "amount": 3000, "read": false, "created_at": "2026-09-16T09:00:00+00:00", "created_human": "2 hours ago" } ],
  "meta": { "unread_count": 3, "can_announce": false, "pagination": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 3 } }
}
```

#### `GET /api/notifications/latest` *(auth)*

The glanceable list behind the app's bell / badge. Deliberately capped at **8**.

```json
{ "data": { "unread_count": 3, "items": [ /* up to 8 as above */ ] } }
```

#### `POST /api/notifications/{id}/read` *(auth)*

Marks one notification read. The id is looked up **within the caller's own
relation**, so another user's notification id simply does not resolve.

**200** -> `{ "data": { "unread_count": 2 }, "message": "Marked as read." }`

#### `POST /api/notifications/read-all` *(auth)*

**200** -> `{ "data": { "unread_count": 0 }, "message": "All notifications marked as read." }`

#### `DELETE /api/notifications/{id}` *(auth)*

**200** -> `{ "message": "Notification removed." }`

#### `POST /api/notifications/announce` — institution broadcast *(auth, `notifications.announce`)*

Permission-gated; on mobile only an **Institution Admin** holds it (the SSA — the
other holder on web — is blocked from the API entirely).

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | ✅ | max 120 |
| `body` | string | ✅ | max 1000 |

**201** -> `{ "data": { "recipients": 42 }, "message": "Announcement sent to 42 user(s)." }`

**Failures:** `403` if the caller does not belong to the active institution.

---

## 6.7d Claims

#### `GET /api/claims?per_page=` — the member's own claims *(auth)*

```json
{
  "data": [ { "id": 4, "kind": "dispute", "kind_label": "Missing entry / dispute", "subject": "meal", "subject_label": "Missing meal", "title": "Dinner on the 14th", "summary": "...", "description": null, "amount": 300, "status": "pending", "status_label": "Pending", "entry_date": "14 Sep 2026", "breakfast": 1, "lunch": 0, "dinner": 1, "claim_date": "16 Sep 2026", "payment_method": null, "review_notes": null, "created_at": "16 Sep 2026, 09:00", "student": { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045" } } ],
  "meta": { "has_member_record": true, "kinds": [ { "value": "dispute", "label": "Missing entry / dispute" } ], "subjects": { "meal": "Missing meal" }, "pagination": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 } }
}
```

#### `POST /api/claims` — raise a claim *(auth)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `kind` | string | ✅ | `dispute` \| `expense` |
| `subject` | string | ✕ | e.g. `meal` |
| `amount` | number | ✕ | required when `kind=expense` |
| `title` | string | ✅ | max 255 |
| `description` | string | ✕ | max 2000 |
| `claim_date` | date | ✕ | defaults to today |
| `payment_method` | string | ✕ | max 60 |
| `entry_date` | date | ✕ | required when `subject=meal` |
| `breakfast` / `lunch` / `dinner` | int | ✕ | 0-10; at least one must be > 0 for a meal dispute |

**201** -> `{ "data": { ...claim... }, "message": "Claim submitted. Your manager will review it." }`

**422** with a field-keyed `errors` object when the guard rails fail (an expense
with no amount; a meal dispute with no meals or no date).

**409** when the account is not linked to a member record.

#### `GET /api/claims/review?status=&kind=&per_page=` — the review queue *(auth, `claims.review`)*

Scoped by BOTH institution and manager assignment: an Institution Admin sees every
claim in the institution; a **Meal Manager sees only claims from members assigned
to them**. The `meta.scoped_to_assigned` flag tells the UI which mode it is in.

```json
{
  "data": [ /* claims, each with `reviewer` + `reviewed_at` */ ],
  "meta": {
    "stats": { "pending": 4, "approved": 12, "rejected": 1 },
    "kinds": [ { "value": "dispute", "label": "Missing entry / dispute" } ],
    "scoped_to_assigned": true,
    "pagination": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 4 }
  }
}
```

#### `PATCH /api/claims/{claim}/approve` *(auth, `claims.review`)*

**This is the point at which money moves** — the same transaction the web runs.

| Field | Type | Required | Notes |
|---|---|---|---|
| `review_notes` | string | ✕ | max 1000 |
| `approved_amount` | number | ✕ | a manager may approve a **partial** amount |

**200** -> `{ "data": { ...claim... }, "message": "Claim approved and the member's balance updated." }`

**Failures:** `403` when the caller cannot review that claim (another institution,
or a member not assigned to this manager); `409` when it was already reviewed.

#### `PATCH /api/claims/{claim}/reject` *(auth, `claims.review`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `review_notes` | string | ✕ | max 1000 |

**200** -> `{ "data": { ...claim... }, "message": "Claim rejected." }`

---

## 6.7e Member payments

**The safety rule:** a member can RECORD a payment, but only a manager can
ACKNOWLEDGE it — and only acknowledgement moves the balance.

#### `GET /api/me/payments` — the member's own submissions *(auth)*

```json
{
  "data": [ { "id": 3, "reference": "MP-2026-0003", "amount": 3000, "method": "bkash", "method_label": "bKash", "status": "pending", "status_tone": "amber", "payer_reference": "TRX9921", "note": "September top-up", "review_note": null, "created_at": "2026-09-16T09:00:00+00:00", "reviewed_at": null } ],
  "meta": { "has_member_record": true, "balance": 33.87, "pending_total": 3000, "methods": [ { "value": "bkash", "label": "bKash" } ], "pagination": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 } }
}
```

#### `POST /api/me/payments` — submit a payment intent *(auth)*

Creates a **PENDING** intent only. It deliberately does **not** create a deposit —
that is what stops a member crediting their own account.

| Field | Type | Required | Notes |
|---|---|---|---|
| `amount` | number | ✅ | 1 - 1,000,000 |
| `method` | string | ✅ | a key of `MemberPayment::METHODS` |
| `payer_reference` | string | ✕ | max 120 (the sender's transaction id) |
| `note` | string | ✕ | max 500 |

**201** -> `{ "data": { ...payment... }, "message": "Payment submitted. Your manager will verify it... Reference MP-2026-0003." }`

**409** — a duplicate guard: the same member submitting the same amount twice
within a minute is almost always a double-tap, not intent. The guard lives on the
**server** precisely because a flaky mobile connection invites re-taps.

#### `GET /api/member-payments?status=` — the verification queue *(auth, `meals.deposit`)*

```json
{
  "data": [ { "id": 3, "reference": "MP-2026-0003", "amount": 3000, "status": "pending", "student": { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045" }, "reviewer": null } ],
  "meta": { "summary": { "pending": 2, "pending_total": 6000, "approved_this_month": 12000 }, "pagination": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 2 } }
}
```

#### `PATCH /api/member-payments/{id}/approve` *(auth, `meals.deposit`)*

**This is the moment money actually moves.** It creates a real `Deposit` inside a
transaction — the same row any manager would record — so the member's balance, the
roster, the reports and the ledger all agree afterwards.

| Field | Type | Required | Notes |
|---|---|---|---|
| `review_note` | string | ✕ | max 500 |

**200** -> `{ "data": { ...payment, "status": "approved"... }, "message": "Payment MP-2026-0003 approved..." }`

**409** when the payment was already reviewed.

#### `PATCH /api/member-payments/{id}/reject` *(auth, `meals.deposit`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `review_note` | string | **required** | max 500 — the member sees this reason |

**200** -> `{ "message": "Payment MP-2026-0003 rejected." }`

---

## 6.7f Meal menus & voting

#### `GET /api/menus?status=&meal_type=&per_page=` *(auth)*

A **member sees only menus with `status: "voting"`** (a draft is internal to the
kitchen); staff see every status.

```json
{
  "data": [
    {
      "id": 2, "title": "Friday dinner", "meal_type": "dinner", "meal_type_label": "Dinner",
      "menu_date": "2026-09-18", "status": "voting", "status_label": "Voting open", "status_tone": "sky",
      "description": "Choose the main course.", "voting_closes_at": "2026-09-18T15:00:00+00:00",
      "total_votes": 42, "is_open_for_voting": true, "allow_vote_changes": true, "has_voted": false,
      "options": [
        { "id": 5, "name": "Beef tehari", "description": null, "estimated_cost": 120, "is_recommended": true, "votes": 30, "percentage": 71.4, "is_my_choice": false },
        { "id": 6, "name": "Chicken biryani", "description": null, "estimated_cost": 110, "is_recommended": false, "votes": 12, "percentage": 28.6, "is_my_choice": false }
      ],
      "approved_at": null, "created_at": "2026-09-17T09:00:00+00:00"
    }
  ],
  "meta": { "statuses": ["draft","voting","approved","rejected","cancelled"], "meal_types": { "dinner": "Dinner" }, "can_manage": false, "pagination": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 } }
}
```

- `is_open_for_voting` is derived **server-side** from the status AND the
  opens/closes window. Never recompute it on the client.
- `is_my_choice` marks the option the caller already picked.

#### `GET /api/menus/{menu}` *(auth)*

Same shape for a single menu, plus `meta.can_manage`, `meta.statuses` and
`meta.meal_types`.

#### `POST /api/menus/{menu}/vote` — cast or change your vote *(auth)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `option_id` | int | ✅ | must belong to **this** menu |
| `comment` | string | ✕ | max 500 |

**200** -> the refreshed menu (tallies recomputed), so no second fetch is needed.

**One vote per member per menu.** A repeat vote **updates** the existing row rather
than adding a second — enforced by a unique index on `(meal_menu_id, user_id)`.

**Failures:** `409` when the menu is not open for voting; `422` when the option id
does not belong to this menu; `409` when the account has no member record.

#### `POST /api/menus` — propose a menu *(auth, `meals.reports`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | ✅ | max 180 |
| `meal_type` | string | ✅ | `breakfast` \| `lunch` \| `dinner` \| `snack` |
| `menu_date` | date | ✕ | defaults to today |
| `description` | string | ✕ | max 1000 |
| `options` | array | ✅ | min 2 |
| `options[].name` | string | ✅ | max 180 |
| `options[].description` | string | ✕ | max 500 |
| `options[].estimated_cost` | number | ✕ | per serving |

**201** -> the created menu (status `draft`).

#### `PATCH /api/menus/{menu}/status` — move the lifecycle *(auth, `meals.reports`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `status` | string | ✅ | `draft` \| `voting` \| `approved` \| `rejected` \| `cancelled` |
| `approval_note` | string | ✕ | max 1000; recorded when approving |

**200** -> the updated menu. Approving stamps `approved_by` and `approved_at`.

---

## 6.7g Expenses, vendors & departments

#### `GET /api/expenses?month=&vendor=&per_page=` *(auth, `meals.expense`)*

```json
{
  "data": [ { "id": 12, "description": "Weekly vegetables", "amount": 8500, "category": "vegetables", "vendor": "Mirpur Fresh Vegetables", "vendor_id": 3, "payment_status": "paid", "recorded_by": "Rakib Hasan", "is_reversed": false, "date": "2026-09-15T09:00:00+00:00" } ],
  "meta": { "month": "2026-09", "total": 8500, "pagination": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 } }
}
```

`meta.total` counts only **non-reversed** expenses, so a reversed row does not
inflate the month.

#### `POST /api/expenses` — record a kitchen expense *(auth, `meals.expense`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `amount` | number | ✅ | min 0.01 |
| `description` | string | ✕ | max 255 |
| `category` | string | ✕ | max 120 |
| `vendor_id` | int | ✕ | **scoped to your institution** (a foreign id is a 422) |
| `payment_status` | string | ✕ | `paid` (default) \| `unpaid` \| `partial` |

**201** -> `{ "data": { "id": 12, "amount": 8500 }, "message": "Expense recorded as a cash-out transaction." }`

> A ledger cash-out transaction is written **first**, then the module row links to
> it. Both happen in one database transaction, so the pool totals and the module
> can never disagree.

#### `GET /api/vendors?search=&category=&status=&per_page=` *(auth, `vendors.view`)*

```json
{
  "data": [ { "id": 3, "name": "Mirpur Fresh Vegetables", "category": "vegetables", "category_label": "Vegetables", "contact_person": "Procurement Desk", "phone": "+8801712345678", "email": null, "recurrence": "daily", "status": "active", "is_hub": false, "opening_balance": 0, "outstanding_balance": 8500, "expenses_count": 4 } ],
  "meta": { "categories": [ { "value": "groceries", "label": "Groceries" } ], "recurrences": [ { "value": "daily", "label": "Daily" } ], "pagination": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 4 } }
}
```

#### `POST /api/vendors` *(auth, `vendors.manage`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | max 255 |
| `category` | string | ✕ | a `Vendor::CATEGORIES` key |
| `recurrence` | string | ✕ | a `Vendor::RECURRENCES` key |
| `contact_person` | string | ✕ | max 255 |
| `phone` | string | ✕ | max 30 |
| `email` | string | ✕ | valid email |
| `opening_balance` | number | ✕ | |
| `status` | string | ✕ | `active` \| `inactive` |

**201** -> `{ "data": { "id": 9, "name": "New Vendor" }, "message": "Vendor created." }`

#### `GET /api/departments?search=&per_page=` *(auth, `departments.view`)*

```json
{
  "data": [ { "id": 1, "name": "Computer Science", "slug": "computer-science", "description": null, "students_count": 12, "active_students_count": 11 } ],
  "meta": { "pagination": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 4 } }
}
```

#### `POST /api/departments` *(auth, `departments.manage`)*

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | **unique within your institution**, max 255 |
| `slug` | string | ✕ | **unique within your institution**, max 255 |
| `description` | string | ✕ | max 1000 |

**201** -> `{ "data": { "id": 5, "name": "Kitchen" }, "message": "Department created." }`

---

## 6.7h Institution settings

#### `GET /api/settings/institution` *(auth, `institution.view`)*

```json
{
  "data": {
    "id": 1, "name": "North South University Hall", "subtitle": "Residential hall meal programme",
    "type": "university_dorm", "type_label": "University Dormitory",
    "logo_url": null, "contact_email": "hello@nsu.test", "contact_phone": "+8801700000000",
    "invite_code": "AB12CD34",
    "currency": { "symbol": "৳", "symbol_position": "before", "decimal_precision": 2 },
    "terms": { "member": "Student", "members": "Students" },
    "theme": { "accent": "indigo", "mode": "light", "radius": "lg" },
    "subsidy_mode": "pool", "member_limit": 250,
    "subscription": { "plan": "growth", "status": "paid", "amount": 2990 }
  },
  "meta": { "can_manage": true }
}
```

`meta.can_manage` is false for a **Meal Manager** (they hold `institution.view`
but not `.manage`), so the app hides the edit affordances.

#### `PUT /api/settings/institution` *(auth, `institution.manage`)*

Send as **`multipart/form-data`** when uploading a `logo`.

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✕ | max 255 |
| `subtitle` | string | ✕ | max 255 |
| `contact_email` | string | ✕ | valid email |
| `contact_phone` | string | ✕ | max 30 |
| `subsidy_mode` | string | ✕ | max 40 |
| `logo` | file | ✕ | image, max 2048 KB |

**200** -> `{ "data": { "id": 1, "name": "...", "logo_url": "..." }, "message": "Institution settings updated." }`

#### `GET /api/settings/subsidy-sources` *(auth, `subsidies.view`)*

The funding sources configured for this institution.

```json
{
  "data": [ { "id": 1, "name": "NSU Authority Grant", "key": "university_authority", "percentage": 60, "description": null, "is_active": true } ],
  "meta": { "can_manage": false, "platform_sources": { "university_authority": "University Authority" } }
}
```

---

### 6.8 Reports & analytics

All reports are month-scoped via `?month=YYYY-MM` (defaults to the current month)
and reuse the shared month snapshot.

#### `GET /api/reports/meal?month=` *(auth)*

The full meal report: the month snapshot plus a per-member breakdown.

```json
{
  "data": {
    "month": "2026-09",
    "label": "September 2026",
    "summary": { "month": "2026-09", "label": "September 2026", "meals": 97, "expenses": 2288.12, "deposits": 1712.25, "subsidies": 0, "per_meal_rate": 23.5889, "meal_cost": 2288.12, "subsidy_coverage_pct": 0, "member_funded_pct": 74.83, "member_shortfall": 575.87, "pool_balance": -575.87, "members": 4, "meals_per_member": 24.3, "cost_per_member": 572.03, "daily_meals": 3.2, "daily_cost": 76.27 },
    "members": [
      { "id": 1, "name": "Farhan", "roll": "CS-045", "department": "CSE", "status": "active", "has_account": true, "breakfast": 8, "lunch": 9, "dinner": 7, "meals": 24, "meal_cost": 566.13, "deposited": 600, "subsidy_share": 0, "balance": 33.87, "is_due": false }
    ],
    "currency": { "symbol": "৳", "symbol_position": "before", "decimal_precision": 2 }
  }
}
```

`members` is sorted by meal count, descending. `currency` may be `null`.

---

#### `GET /api/reports/analytics?month=` *(auth)*

Month snapshot, subsidy tracking, and the forecast in one call.

```json
{
  "data": {
    "month": "2026-09",
    "snapshot": { "meals": 97, "expenses": 2288.12, "deposits": 1712.25, "subsidies": 0, "per_meal_rate": 23.5889, "meal_cost": 2288.12, "subsidy_coverage_pct": 0, "member_funded_pct": 74.83, "member_shortfall": 575.87, "pool_balance": -575.87, "members": 4 },
    "subsidy_tracking": { "total": 0, "coverage_pct": 0 },
    "forecast": { "history": [], "forecast": {}, "horizon": [], "assumptions": {} }
  }
}
```

`forecast` is the full predictive payload (see the next endpoint); it may be an
empty shell for a brand-new institution with no history.

---

#### `GET /api/reports/forecast?months=3` — the predictive engine *(auth)*

| Query | Type | Notes |
|---|---|---|
| `months` | int | look-back window; default **3**, clamped to **2–12** |

```json
{
  "data": {
    "history": [
      { "month": "2026-07", "label": "July 2026", "meals": 90, "expenses": 1700, "subsidies": 0, "deposits": 1600, "per_meal_rate": 18.8889, "meal_cost": 1700, "subsidy_coverage_pct": 0 }
    ],
    "forecast": {
      "next_month": "October 2026",
      "projected_meals": 100,
      "projected_expenses": 2400.5,
      "projected_rate": 24.0,
      "projected_cost": 2400.5,
      "subsidy_required": 480.1,
      "member_funded": 1920.4,
      "meal_growth_pct": 0,
      "expense_growth_pct": 35
    },
    "horizon": [
      { "month": "2026-10", "label": "October 2026", "projected_meals": 100, "projected_expenses": 2400.5, "projected_rate": 24.0, "projected_cost": 2400.5, "subsidy_required": 480.1, "member_funded": 1920.4 }
    ],
    "assumptions": {
      "lookback_months": 3,
      "method": "Recency-weighted average with a clamped growth rate.",
      "target_subsidy_ratio": 20,
      "target_member_ratio": 80,
      "has_history": true
    }
  }
}
```

Method (deliberately simple and explainable): a **recency-weighted average** of the
look-back months, with a **clamped growth rate** applied forward; the subsidy
target follows the institution's configured ratio (the "80/20 rule" by default).

---

#### `GET /api/reports/per-meal-rate?month=` — the rate, explained *(auth)*

Breaks the per-meal rate into the parts that make it up.

```json
{
  "data": {
    "month": "2026-09",
    "formula": "per_meal_rate = total_expense / total_meals",
    "total_expense": 2288.12,
    "total_meals": 97,
    "per_meal_rate": 23.5889,
    "daily_meals": 3.2,
    "daily_cost": 76.27,
    "meals_per_member": 24.3,
    "cost_per_member": 572.03,
    "subsidy_coverage_pct": 0,
    "member_funded_pct": 74.83,
    "formatted": {
      "total_expense": "৳2,288.12",
      "per_meal_rate": "23.5889"
    }
  }
}
```

`formatted` is a server-side convenience for display; numeric clients should use
the numeric fields.

---

## 7. Errors — worked examples

### 422 validation

```http
POST /api/deposits
Authorization: Bearer 3|...
Content-Type: application/json
Accept: application/json

{ "amount": -5 }
```

```json
{
  "message": "The amount field must be at least 0.01.",
  "errors": { "amount": ["The amount field must be at least 0.01."] }
}
```

The client should show `errors[field]` beside the offending input and `message` as
the general banner.

### 401 unauthenticated

Missing/expired/invalid token:

```json
{ "message": "Unauthenticated." }
```

Clear stored credentials and return to login.

### 403 forbidden / inactive

```json
{ "message": "This account is inactive." }
```

Also returned when a role/permission check denies the action.

### 404 not found / not in tenant

A resource id that is not in the caller's institution (route-model binding is
tenant-scoped), or a genuinely missing id:

```json
{ "message": "No query results for model [App\\Models\\Student]." }
```

### 409 conflict

```json
{ "message": "This member has meal or deposit history and cannot be deleted." }
```

### 429 throttled

See §3 — includes a `Retry-After` header.

---

## 8. Appendix A — Web (Inertia) routes

The browser application is a Laravel + **Inertia/React** SPA. This appendix maps
each web **route name** to its **URI** and its **Inertia page component** (the file
under `resources/js/Pages`). It is reference material for a developer new to the web
surface; the mobile API (§6) does not use these.

Routes marked **redirect** or **JSON** do not render an Inertia page (they bounce
to another screen or return a plain JSON/file response). `POST`/`PUT`/`PATCH`/
`DELETE` action routes redirect back or return redirects/JSON and are noted where
they carry a name.

### 8.1 Public / authentication `routes/auth.php`

| Route name | URI | Inertia component |
|---|---|---|
| `home` | `GET /` | `Welcome` (signed-in users are redirected to `dashboard`) |
| `landing.contact` | `POST /contact` | redirect (lead capture) |
| `password.setup` | `GET /password/setup/{invitation}` | `Auth/PasswordSetup` (signed link) |
| `password.setup.store` | `POST /password/setup/{invitation}` | — (action) |
| `invitations.accept` | `GET /invitations/{invitation}/accept` | `Auth/PasswordSetup` (legacy alias) |
| `invitations.complete` | `POST /invitations/{invitation}/complete` | — (action) |
| `register` | `GET /register` | `Auth/Register` |
| `login` | `GET /login` | `Auth/Login` |
| `password.request` | `GET /forgot-password` | `Auth/ForgotPassword` |
| `password.reset` | `GET /reset-password/{token}` | `Auth/ResetPassword` |
| `verification.notice` | `GET /verify-email` | `Auth/VerifyEmail` |
| `password.confirm` | `GET /confirm-password` | `Auth/ConfirmPassword` |
| `password.change` | `GET /password/change` | `Auth/ChangePassword` |

### 8.2 Dashboard, member area & account

| Route name | URI | Inertia component |
|---|---|---|
| `dashboard` | `GET /dashboard` | `Dashboard` |
| `member.dashboard` | `GET /my/dashboard` | `Member/Dashboard` |
| `member.meals` | `GET /my/meals` | `Member/Meals` |
| `member.deposits` | `GET /my/deposits` | `Member/Deposits` |
| `member.analytics` | `GET /my/analytics` | `Member/Analytics` |
| `analytics` | `GET /analytics` | `Analytics` |
| `profile.edit` | `GET /profile` | `Profile/Edit` |
| `profile.update` | `PATCH /profile` | — (action) |
| `profile.destroy` | `DELETE /profile` | — (action) |
| `notifications.index` | `GET /notifications` | `Notifications/Index` |
| `notifications.latest` | `GET /notifications/latest` | JSON |
| `notifications.announce` | `POST /notifications/announce` | — (action) |
| `notifications.read` | `PATCH /notifications/{notification}/read` | — (action) |
| `notifications.readAll` | `POST /notifications/read-all` | — (action) |
| `notifications.destroy` | `DELETE /notifications/{notification}` | — (action) |
| `claims.index` | `GET /claims` | `Claims/Index` |
| `claims.store` | `POST /claims` | — (action) |
| `claims.review` | `GET /claims/review` | `Claims/Review` |
| `claims.approve` | `PATCH /claims/{claim}/approve` | — (action) |
| `claims.reject` | `PATCH /claims/{claim}/reject` | — (action) |
| `transactions.create` | `GET /transactions/create` | redirect (legacy → correct module) |
| `transactions.store` | `POST /transactions` | — (action) |

### 8.3 Meals & operations (`/meals/*`, name prefix `meals.`)

| Route name | URI | Inertia component |
|---|---|---|
| `meals.departments.index` | `GET /meals/departments` | `Meals/Departments/Index` |
| `meals.departments.create` | `GET /meals/departments/create` | redirect → index |
| `meals.departments.edit` | `GET /meals/departments/{department}/edit` | redirect → index |
| `meals.students.index` | `GET /meals/students` | `Meals/Students/Index` |
| `meals.students.show` | `GET /meals/students/{student}` | `Meals/Students/Show` |
| `meals.students.create` | `GET /meals/students/create` | redirect → index |
| `meals.students.edit` | `GET /meals/students/{student}/edit` | redirect → index |
| `meals.students.export` | `GET /meals/students-export` | file download |
| `meals.students.invite` | `POST /meals/students/{student}/invite` | — (action) |
| `meals.deposits.index` | `GET /meals/deposits` | `Meals/Deposits/Index` |
| `meals.deposits.create` | `GET /meals/deposits/create` | redirect → index |
| `meals.deposits.reverse` | `PATCH /meals/deposits/{deposit}/reverse` | — (action) |
| `meals.deposits.export` | `GET /meals/deposits/export` | file download |
| `meals.entries.index` | `GET /meals/entries` | `Meals/Entries/Index` |
| `meals.entries.create` | `GET /meals/entries/create` | `Meals/Entries/Create` |
| `meals.expenses.index` | `GET /meals/expenses` | `Meals/Expenses/Index` |
| `meals.expenses.create` | `GET /meals/expenses/create` | redirect → index |
| `meals.expenses.reverse` | `PATCH /meals/expenses/{expense}/reverse` | — (action) |
| `meals.reports.index` | `GET /meals/reports` | `Meals/Reports/Index` |
| `meals.reports.export` | `GET /meals/reports/export` | file download |
| `meals.subsidies.index` | `GET /meals/subsidies` | `Meals/Subsidies/Index` |
| `meals.subsidies.reverse` | `PATCH /meals/subsidies/{subsidy}/reverse` | — (action) |
| `meals.vendors.index` | `GET /meals/vendors` | `Meals/Vendors/Index` |
| `meals.vendors.history` | `GET /meals/vendors/{vendor}/history` | JSON |
| `meals.vendors.export` | `GET /meals/vendors/export` | file download |

### 8.4 Settings (workspace administration)

| Route name | URI | Inertia component |
|---|---|---|
| `settings` | `GET /settings` | redirect → `/settings/currency` |
| `settings.currency` | `GET /settings/currency` | `Settings/CurrencyManager` |
| `settings.currency.store` | `POST /settings/currency` | — (action) |
| `settings.theme.edit` | `GET /settings/theme` | `Settings/ThemeCustomizer` |
| `settings.theme.update` | `PUT /settings/theme` | — (action) |
| `settings.theme.reset` | `POST /settings/theme/reset` | — (action) |
| `settings.activity.index` | `GET /settings/activity` | `Settings/ActivityLog` |
| `settings.emails.index` | `GET /settings/emails` | `Settings/EmailLog` |
| `settings.emails.show` | `GET /settings/emails/{emailLog}` | `Settings/EmailLog` |
| `settings.roles.index` | `GET /settings/roles` | `Settings/RoleManager` |
| `settings.roles.create` | `GET /settings/roles/create` | `Roles/Form` |
| `settings.roles.store` | `POST /settings/roles` | — (action) |
| `settings.roles.edit` | `GET /settings/roles/{role}/edit` | `Roles/Form` |
| `settings.roles.update` | `PUT /settings/roles/{role}` | — (action) |
| `settings.roles.destroy` | `DELETE /settings/roles/{role}` | — (action) |
| — (unnamed) | `GET /settings/roles/permissions` | JSON |
| `settings.users.index` | `GET /settings/users` | `Settings/UserManager` |
| `settings.users.store` | `POST /settings/users` | — (action) |
| `settings.users.update` | `PUT /settings/users/{user}` | — (action) |
| `settings.users.deactivate` | `PATCH /settings/users/{user}/deactivate` | — (action) |
| `settings.users.activate` | `PATCH /settings/users/{user}/activate` | — (action) |
| `settings.users.destroy` | `DELETE /settings/users/{user}` | — (action) |
| `users.index` | `GET /users` | redirect → `/settings/users` |
| `settings.institution.edit` | `GET /settings/institution` | `Settings/InstitutionSettings` |
| `settings.institution.update` | `PUT /settings/institution` | — (action) |
| `settings.invite-code.show` | `GET /settings/invite-code` | `Settings/InviteCode` |
| `settings.invite-code.regenerate` | `POST /settings/invite-code/regenerate` | — (action) |
| `settings.subsidy-sources.index` | `GET /settings/subsidy-sources` | `Settings/SubsidySources` |
| `settings.subsidy-sources.store` | `POST /settings/subsidy-sources` | — (action) |
| `settings.subsidy-sources.update` | `PUT /settings/subsidy-sources/{subsidySource}` | — (action) |
| `settings.subsidy-sources.destroy` | `DELETE /settings/subsidy-sources/{subsidySource}` | — (action) |

### 8.5 Roles & users (standalone `RoleController`)

| Route name | URI | Inertia component |
|---|---|---|
| `roles.index` | `GET /roles` | `Roles/Index` |
| `roles.create` | `GET /roles/create` | `Roles/Form` |
| `roles.store` | `POST /roles` | — (action) |
| `roles.edit` | `GET /roles/{role}/edit` | `Roles/Form` |
| `roles.update` | `PUT /roles/{role}` | — (action) |
| `roles.destroy` | `DELETE /roles/{role}` | — (action) |

### 8.6 Software Super Admin (platform)

| Route name | URI | Inertia component |
|---|---|---|
| `settings.institutions.index` | `GET /settings/institutions` | `Settings/InstitutionRegistry` |
| `settings.institutions.store` | `POST /settings/institutions` | — (action) |
| `settings.institutions.toggle` | `PATCH /settings/institutions/{institution}/toggle` | — (action) |
| `settings.institutions.switch` | `PATCH /settings/institutions/{institution}/switch` | — (session tenant switch) |
| `settings.institutions.exit` | `POST /settings/institutions/exit` | — (session tenant clear) |
| `settings.monitoring.index` | `GET /settings/monitoring` | `Settings/Monitoring` |
| `settings.monitoring.subscription` | `PUT /settings/monitoring/{institution}/subscription` | — (action) |
| `settings.monitoring.audit.export` | `GET /settings/monitoring/audit/export` | file download |
| `ssa.dashboard` | `GET /platform` | `Settings/Monitoring` |
| `ssa.analytics` | `GET /platform/analytics` | `SSA/Analytics` |
| `ssa.audit.index` | `GET /platform/audit` | `SSA/Audit` |
| `ssa.audit.export` | `GET /platform/audit/export` | file download |
| `ssa.enquiries.index` | `GET /platform/enquiries` | `SSA/Enquiries` |
| `ssa.enquiries.approve` | `POST /platform/enquiries/{enquiry}/approve` | — (action) |
| `ssa.enquiries.contact` | `POST /platform/enquiries/{enquiry}/contact` | — (action) |
| `ssa.enquiries.reject` | `POST /platform/enquiries/{enquiry}/reject` | — (action) |
| `ssa.broadcasts.index` | `GET /platform/broadcasts` | `SSA/Broadcasts` |
| `ssa.broadcasts.store` | `POST /platform/broadcasts` | — (action) |
| `ssa.plans.index` | `GET /platform/plans` | `SSA/Plans` |
| `ssa.plans.store` | `POST /platform/plans` | — (action) |
| `ssa.plans.update` | `PUT /platform/plans/{plan}` | — (action) |
| `ssa.plans.destroy` | `DELETE /platform/plans/{plan}` | — (action) |
| `ssa.plans.assign` | `POST /platform/plans/assign/{institution}` | — (action) |
| `settings.trials.index` | `GET /settings/trials` | `Settings/TrialManagement` |
| `settings.trials.remind` | `POST /settings/trials/{institution}/remind` | — (action) |
| `settings.trials.convert` | `POST /settings/trials/{institution}/convert` | — (action) |
| `settings.trials.extend` | `POST /settings/trials/{institution}/extend` | — (action) |

---

## 9. Appendix B — enum reference

Values the client must send or may receive, gathered for convenience.

**Deposit `kind`**

| Key | Label |
|---|---|
| `personal` | Personal deposit (the default) |
| `subsidy` | Institutional subsidy |
| `credit` | Credit adjustment |

**Subsidy `apply_mode`**

| Key | Label |
|---|---|
| `pool` | Into the common pool |
| `per_member` | Split per active member |
| `credit_behind` | Reserve (applied after member funds) |

**Subsidy `source` (platform defaults)** — `university_authority`,
`company_management`, `college_administration`, `government_grant`, `donation`,
`other`. An institution may also define its own sources; see
`GET /api/subsidies/sources`.

**Member `status`** — `active` \| `inactive`.

**User `status`** — `active` \| `inactive` (an inactive account cannot log in).

All of the above are also discoverable at runtime from `GET /api/meta`.
