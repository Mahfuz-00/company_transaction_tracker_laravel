# API Reference

The mobile application talks to this Laravel backend over a JSON API at
`/api`. Authentication is **Laravel Sanctum** personal access tokens.

- **Base URL:** `https://your-host/api`
- **Auth header:** `Authorization: Bearer <token>`
- **Accepts:** `application/json` (always send `Accept: application/json`)
- **Dates:** ISO-8601 (`2026-09-16`)
- **Months:** `YYYY-MM` (`2026-09`)
- **Money:** returned as a JSON **number** (e.g. `2288.12`). Formatting is the
  client's job; the currency config is served once by `/meta`.

---

## Conventions

| Thing | Rule |
|---|---|
| Success envelope | `{ "data": ... }` |
| Collection envelope | `{ "data": [...], "meta": { "pagination": {...} }` |
| Error envelope | `{ "message": "...", "errors": { "field": ["..."] }` |
| Validation failure | HTTP `422` |
| Not found | HTTP `404` |
| Unauthorised | HTTP `401` |
| Forbidden (role/permission) | HTTP `403` |
| Conflict (e.g. delete blocked) | HTTP `409` |

---

## Authentication

### POST `/auth/login`
Exchange credentials for a token.

```json
{ "email": "user@example.com", "password": "secret", "device_name": "iPhone 15" }
```

**200**
```json
{
  "data": {
    "token": "3|abcdef...",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "QA Dev", "email": "qa@example.com",
              "roles": ["Software Super Admin"], "permissions": ["..."] }
  }
}
```

### POST `/auth/register`
Self-registration. The new account receives the **Member** role.
Body: `name`, `email`, `password`, `password_confirmation`, `device_name?`.

### GET `/auth/me`
Returns the current user. Use on app start to validate a stored token.

### POST `/auth/logout`
Revokes the **calling** token only.

### PATCH `/auth/profile`
Update your own profile. Multipart form-data when sending `avatar`.

| Field | Type | Notes |
|---|---|---|
| `name` | string | required |
| `phone` | string? | |
| `designation` | string? | job title / role label |
| `avatar` | file? | png/jpg/webp, max 2 MB |

### POST `/auth/devices`
Mint an extra token for a recognised device.

---

## Metadata

### GET `/meta` *(public)*
Everything the client needs to render itself correctly. Call this once at
startup and cache it.

```json
{
  "data": {
    "app_name": "Laravel",
    "institution": {
      "id": 1, "name": "Acme Ltd", "subtitle": "Staff cafeteria",
      "type": "company", "type_label": "Company / Corporate Office",
      "logo_url": "https://.../logo.png",
      "terms": { "member": "Employee", "members": "Employees", "department": "Team", "...": "..." },
      "theme": { "accent": "indigo", "mode": "light", "radius": "lg", "density": "comfortable" }
    },
    "currency": { "symbol": "৳", "symbol_position": "before", "decimal_precision": 2, "...": "..." },
    "subsidy_modes": { "pool": "Into the common pool", "...": "..." },
    "subsidy_sources": { "university_authority": "University Authority", "...": "..." },
    "deposit_kinds": { "personal": "Personal deposit", "subsidy": "Institutional subsidy", "credit": "Credit adjustment" },
    "api_version": "1.0.0"
  }
}
```

> **Render with `terms`, never hard-coded nouns.** A company says "Employees",
> a dorm says "Students". The keys are stable; the values change per
> institution.

---

## Dashboard

### GET `/dashboard?month=YYYY-MM`
The month summary in one call.

```json
{
  "data": {
    "month": "2026-09",
    "summary": {
      "meals": 97, "expenses": 2288.12, "deposits": 1712.25, "subsidies": 0,
      "per_meal_rate": 23.5889, "meal_cost": 2288.12,
      "subsidy_coverage_pct": 0, "member_funded_pct": 74.83, "member_shortfall": 575.87,
      "pool_balance": -575.87, "members": 4,
      "meals_per_member": 24.3, "cost_per_member": 572.03,
      "daily_meals": 3.2, "daily_cost": 76.27
    },
    "members": { "total": 5, "active": 4, "with_dues": 2, "total_dues": 575.87 },
    "top_dues": [ { "id": 3, "name": "Farhan", "roll": "CS-045", "balance": -210.5 } ],
    "recent_activity": [ { "id": 12, "type": "in", "label": "Deposit", "amount": 3000, "...": "..." } ]
  }
}
```

---

## Members

### GET `/members?month=&search=&status=&per_page=`
Paginated roster. `month_meals`, `meal_cost` and `balance` are **for the
selected month**, priced at that month's per-meal rate.

```json
{
  "data": [
    { "id": 1, "name": "Farhan Hossain", "roll": "CS-2021-045",
      "department": "CSE", "status": "active", "has_account": true,
      "month_meals": 24, "breakfast": 8, "lunch": 9, "dinner": 7,
      "meal_cost": 566.13, "deposited": 600, "subsidy_share": 0,
      "balance": 33.87, "is_due": false }
  ],
  "meta": { "month": "2026-09", "per_meal_rate": 23.5889, "departments": [...],
            "pagination": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 4 } }
}
```

### GET `/members/{id}?month=`
Full member detail: `figures` for the month, `recent_deposits`, `recent_meals`,
and the linked `account`.

### POST `/members`
`name` (required), `roll`, `department_id`, `manager_id`, `join_date`, `status`.

### PATCH `/members/{id}` · DELETE `/members/{id}`
Update / remove. Deletion returns **409** if the member has any meal or deposit
history — deactivate them instead.

---

## Deposits (Cash In)

### GET `/deposits?month=&member=&kind=&per_page=`
```json
{
  "data": [ { "id": 9, "member": "Farhan", "member_id": 1, "amount": 3000,
              "kind": "personal", "payment_method": "bKash", "date": "2026-09-10T09:00:00Z" } ],
  "meta": { "month": "2026-09", "totals": { "all": 4712.25, "personal": 4712.25, "subsidy": 0 },
            "kinds": { "personal": "Personal deposit", "...": "..." }, "members": [...] }
}
```

### POST `/deposits`
| Field | Type | Notes |
|---|---|---|
| `student_id` | int | required |
| `amount` | number | > 0 |
| `payment_method` | string? | Cash, bKash, ... |
| `kind` | string? | `personal` (default) \| `subsidy` \| `credit` |
| `notes` | string? | |

Creates the deposit **and** a matching Cash-In ledger transaction in one
database transaction.

### GET `/deposits/export?month=`
Flat JSON of the month's deposits, for client-side export.

---

## Meals

### GET `/meals?month=&member=&per_page=`
Historical entries for a month, plus `meta.totals` (breakfast/lunch/dinner/total).

### GET `/meals/day?date=YYYY-MM-DD`
The **day grid**: every active member with what is already recorded for that
date, plus the day's totals. This is what the "record meals" screen loads.

```json
{ "data": {
    "date": "2026-09-16",
    "members": [ { "id": 1, "name": "Farhan", "roll": "CS-045",
                   "breakfast": 1, "lunch": 1, "dinner": 0 } ],
    "totals": { "breakfast": 4, "lunch": 4, "dinner": 3, "total": 11 }
} }
```

### POST `/meals/day`
Save the whole day in one request.

```json
{ "date": "2026-09-16",
  "entries": [ { "student_id": 1, "breakfast": 1, "lunch": 1, "dinner": 0 } ] }
```

Rows left at zero are **deleted**, so clearing a member's day works.

---

## Subsidies (Institutional funding)

### GET `/subsidies?month=&source=&per_page=`
Month-scoped subsidy records with `meta.total` and `meta.source_totals`.

### GET `/subsidies/sources`
The funding sources configured for this institution (see the admin's
**Settings → Subsidy Sources**).

### POST `/subsidies`
| Field | Type | Notes |
|---|---|---|
| `source` | string | a source `key`, or your own label |
| `source_label` | string? | free-text funder name |
| `amount` | number | > 0 |
| `percentage` | number? | 0–100, the share this funder covers |
| `apply_mode` | string | `pool` \| `per_member` \| `credit_behind` |
| `period_month` | `YYYY-MM`? | defaults to the current month |
| `department_id` / `student_id` | int? | optional scope |

---

## Reports & analytics

### GET `/reports/meal?month=`
The full meal report: `summary` (the month snapshot) + `members` breakdown.

### GET `/reports/analytics?month=`
Month snapshot, subsidy tracking, and the forecast in one call.

### GET `/reports/per-meal-rate?month=`
The rate explained in its parts:

```json
{ "data": {
    "formula": "per_meal_rate = total_expense / total_meals",
    "total_expense": 2288.12, "total_meals": 97, "per_meal_rate": 23.5889,
    "daily_meals": 3.2, "daily_cost": 76.27,
    "subsidy_coverage_pct": 0, "member_funded_pct": 74.83
} }
```

### GET `/reports/forecast?months=3`
The predictive engine.

```json
{ "data": {
  "history": [ { "month": "2026-07", "meals": 90, "expenses": 1700, "...": "..." } ],
  "forecast": {
    "next_month": "October 2026",
    "projected_meals": 100, "projected_expenses": 2400.5,
    "projected_rate": 24.0, "projected_cost": 2400.5,
    "subsidy_required": 480.1, "member_funded": 1920.4,
    "meal_growth_pct": 0, "expense_growth_pct": 35
  },
  "horizon": [ { "month": "2026-10", "projected_cost": 2400.5, "subsidy_required": 480.1 } ],
  "assumptions": { "lookback_months": 3,
                   "method": "Recency-weighted average with a clamped growth rate.",
                   "target_subsidy_ratio": 20, "target_member_ratio": 80 }
} }
```

---

## Error handling — worked example

```http
POST /api/deposits
Authorization: Bearer 3|...
Content-Type: application/json

{ "amount": -5 }
```

**422**
```json
{ "message": "The amount field must be at least 0.01.",
  "errors": { "amount": ["The amount field must be at least 0.01."] } }
```

The client should show `errors[field]` beside the offending input and
`message` as the general banner.
