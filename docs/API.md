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

> This document is the authoritative contract for the mobile client. The
> endpoints below are exactly those registered in `routes/api.php`; the shapes
> come from the controllers and the `app/Http/Resources/*` classes they use.

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

### Documented limitation — Software Super Admin

A **Software Super Admin (SSA)** is a platform-level role. In the **web** app an
SSA uses a session-scoped "switch into a workspace" control. The **mobile API has
no tenant-switch**, and there is no session. As a result, an SSA bearer token
currently resolves to a **GLOBAL, unscoped context** (`TenantManager::resolveTenantId()`
returns `null`):

- Tenant-owned queries run **without** an institution filter, so an SSA token sees
  **all** institutions' data.
- `tenantExists()` has no scope to apply, so the `exists` rules stay **table-wide**
  for an SSA token.
- `GET /api/meta` returns `institution: null` for an SSA token (there is no single
  "current" institution).

This is by design for platform oversight, but it means a mobile SSA token is
**not** tenant-isolated. If the mobile client is ever exposed to SSA accounts,
handle this case explicitly (or restrict the mobile app to non-SSA roles).

---

## 6. Endpoint reference

**26 endpoints total.** Grouped by area. "Auth" indicates whether
`Authorization: Bearer` is required.

### 6.0 Quick index

| # | Method | Path | Auth |
|---|---|---|---|
| 1 | POST | `/api/auth/register` | public |
| 2 | POST | `/api/auth/login` | public |
| 3 | GET | `/api/auth/me` | ✅ |
| 4 | POST | `/api/auth/logout` | ✅ |
| 5 | POST | `/api/auth/devices` | ✅ |
| 6 | PATCH | `/api/auth/profile` | ✅ |
| 7 | GET | `/api/meta` | public |
| 8 | GET | `/api/dashboard` | ✅ |
| 9 | GET | `/api/members` | ✅ |
| 10 | GET | `/api/members/{member}` | ✅ |
| 11 | POST | `/api/members` | ✅ |
| 12 | PATCH | `/api/members/{member}` | ✅ |
| 13 | DELETE | `/api/members/{member}` | ✅ |
| 14 | GET | `/api/deposits` | ✅ |
| 15 | POST | `/api/deposits` | ✅ |
| 16 | GET | `/api/deposits/export` | ✅ |
| 17 | GET | `/api/meals` | ✅ |
| 18 | GET | `/api/meals/day` | ✅ |
| 19 | POST | `/api/meals/day` | ✅ |
| 20 | GET | `/api/subsidies` | ✅ |
| 21 | POST | `/api/subsidies` | ✅ |
| 22 | GET | `/api/subsidies/sources` | ✅ |
| 23 | GET | `/api/reports/meal` | ✅ |
| 24 | GET | `/api/reports/analytics` | ✅ |
| 25 | GET | `/api/reports/forecast` | ✅ |
| 26 | GET | `/api/reports/per-meal-rate` | ✅ |

---

### 6.1 Auth & identity

#### `POST /api/auth/register` — self-registration *(public)*

Registers a new account and returns a token immediately. The account is **always**
assigned the **Member** role (there is no `role` field accepted — never trust a
client for a role). The account is attached to the currently resolved institution.

**Throttle:** `api-login` (5/min per email+IP) **and** baseline `api` (60/min).

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ✅ | max 255 |
| `email` | string | ✅ | valid email, max 255, unique |
| `password` | string | ✅ | must be **confirmed** (send `password_confirmation`) |
| `device_name` | string | ✕ | max 120; used to label the token |

```json
{ "name": "New Member", "email": "new@example.com", "password": "secret123", "password_confirmation": "secret123", "device_name": "Pixel 8" }
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

- `institution` is `null` when no tenant is resolvable (e.g. a fresh install or an
  **SSA token** in the global context — see §5).
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
