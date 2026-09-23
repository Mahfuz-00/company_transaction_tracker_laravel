# User Manual

A practical, role-by-role guide to running a meal & expense (pool) program on this
platform — and, for platform operators, to running the SaaS business that sells it.

This manual is written for the people who actually use the system every day:

- the **Software Super Admin**, who runs the platform itself,
- the **Institution Admin**, who runs one institution,
- the **Meal Manager**, who runs the day-to-day mess operations, and
- the **Member**, who sees their own meals, money and balance.

It is also the onboarding reference for new developers, so it spells out *which
screen lives where, under which role, and what each button does.*

> **Note on versions:** this file (`docs/USER_MANUAL.md`) is the current,
> complete manual. It **supersedes** the older `docs/USER-MANUAL.md`. If you find
> the old file anywhere, it is out of date — use this one.

---

## Contents

1. [What this platform does](#1-what-this-platform-does)
2. [The role hierarchy (who can do what)](#2-the-role-hierarchy-who-can-do-what)
3. [Shared first steps (every role)](#3-shared-first-steps-every-role)
4. [Software Super Admin guide](#4-software-super-admin-guide)
5. [Institution Admin guide](#5-institution-admin-guide)
6. [Meal Manager guide](#6-meal-manager-guide)
7. [Member guide](#7-member-guide)
8. [Common tasks — quick reference](#8-common-tasks--quick-reference)
9. [Troubleshooting & FAQ](#9-troubleshooting--faq)

---

## 1. What this platform does

This is a **multi-institution** (multi-tenant) SaaS. Each institution — a
university dorm, a college dorm, a company canteen, or a general mess — gets its
own private **workspace** inside which it tracks **shared meal money**:

- members **pay money in** (deposits),
- the mess **spends money out** (expenses), and
- members **eat meals**.

From those three things the system works out **who owes what**, and it also
tracks **subsidies** — money injected by an authority (a university, a company, a
college) rather than by a member — kept deliberately separate from members' own
contributions.

The one number everything revolves around:

**Per-meal rate = Total expense ÷ Total meals**

If the mess spent **2,288.12** this month and members ate **97** meals, the rate
is **23.59 per meal**. Multiply that by the meals a member ate to get what they
owe; subtract what they deposited to get their **balance**.

Because every institution is isolated, two institutions can each have their own
"Kitchen" department, their own members, their own currency format and their own
branding, without ever seeing each other's data.

---

## 2. The role hierarchy (who can do what)

There are exactly **four** roles, arranged in **three access tiers**.

### The three tiers

| Tier | Roles | Scope | What that means |
|---|---|---|---|
| **Tier 1 — Global** | Software Super Admin | The whole platform | Owns the platform: provisions institutions, manages SaaS billing/health, switches between workspaces, sees every audit trail. The **only** role with cross-tenant reach. |
| **Tier 2 — Institution-scoped** | Institution Admin & Meal Manager | One institution | Run everything *inside* one workspace. **Zero** cross-tenant reach — they can never see another institution, and never hold platform-wide permissions. Every query they run is automatically filtered to their own institution. |
| **Tier 3 — Personal** | Member | Their own records only | Sees their own meals, deposits, balance and claims. No administrative screen is reachable at all. |

### The four roles

| Role | What they can do |
|---|---|
| **Software Super Admin** | Everything, everywhere. Runs the platform: the institution registry, trials & subscriptions, pricing plans, landing enquiries, platform broadcasts, global audit, and SaaS analytics. Only this role can switch into another institution's workspace. |
| **Institution Admin** | The top authority *within* one institution: members, meal entries, deposits, expenses, subsidies & subsidy sources, departments, vendors, reports, invite codes, users, currency, activity log, email log, and claim review. Can customise institution identity, terminology, branding and theme. |
| **Meal Manager** | The operational subset: the members assigned to them, meal entries, deposits, expenses, departments, vendors, reports, and claim review. Read-only on institution settings, subsidies (the funding data) and currency. Cannot manage users. |
| **Member** | Their own dashboard (summary, balance), their own meal entries, their own deposits, their own analytics, and their own claims (raise + track). Nothing administrative. |

A user can hold a role, and their role determines which modules appear in the
sidebar and which actions the server will allow. **The sidebar and the server
always agree** — if a menu item is missing, the action behind it is blocked too.

---

## 3. Shared first steps (every role)

These steps and preferences apply to *everyone*, whatever their role.

### 3.1 Signing in

1. Go to the site and open the **Log in** page.
2. Enter your **email** and **password**, then submit.
3. The system sends you to the right landing page for your role:
   - a **Software Super Admin** lands on the **Platform (Business) Dashboard** —
     unless they have deliberately switched *into* an institution;
   - an **Institution Admin** or **Meal Manager** lands on their institution
     **Dashboard**;
   - a **Member** lands on their personal **Summary**.

**If you were invited by email** (rather than given a password), you do not log
in first. Your invitation email contains a secure **setup link**:

1. Click the link in the email.
2. Set your **password** (and confirm your name if it was pre-filled).
3. You are sent to the **Log in** page — sign in with your new credentials.

**If you were given a temporary password** by an admin, the system forces you to
set a real one: after you log in you are taken straight to the **Change
Password** screen and cannot leave it until you have set a new password.

**Forgot your password?** Use the **Forgot password** link on the login page.
Enter your email and the system emails you a secure reset link.

**New to the platform and have an invite code?** Use the public **Create your
account** (register) page, enter your institution's **invite code**, your name,
email and a password. The invite code is the key that maps your new account to
the right institution's workspace. New self-signups join as a **Member** (or Meal
Manager, if your institution allows it) — an admin can grant more access later.

### 3.2 Your profile page

Open **Profile Manager** — the fastest way is to **click your name in the sidebar
footer**. On the profile page you can:

1. Change your **name** (for a member, this also updates the name shown on the
   institution's roster, so the two never disagree),
2. Change your **email** and **phone**,
3. Upload or remove a **profile picture** (avatar) — it appears in the sidebar and
   beside your entries in the activity log,
4. See your **roles**, your **institution**, and when you joined.

Staff accounts also carry a **designation** (a job title) that you can edit here.
You can also **delete your account** from this page (you must confirm your
password).

### 3.3 Changing your password

Open the **Change Password** screen (reachable from your profile, or forced on
first login if you hold a temporary password):

1. Enter your **current password**.
2. Enter your **new password**, then **confirm** it.
3. Save. The new password meets the platform's strength rules (server-checked).

### 3.4 Choosing a theme (look & feel)

Open **Theme Customizer** (under the **Account** section of the sidebar — it is
available to every role, including members). You can pick:

| Setting | Options |
|---|---|
| **Mode** | Light or dark |
| **Accent colour** | A palette of swatches applied across the whole workspace |
| **Corner radius** | Sharp, Slightly rounded, Rounded, Very rounded |
| **Density** | Compact, Comfortable, Spacious (how much spacing the screens use) |
| **Font** | A set of readable typefaces |

Your theme is **personal**: it is saved to your account, so it follows you to any
device you sign in from, and it also applies instantly on the current device. Use
**Reset** to return to the platform default.

> The theme is *yours*, not the institution's. Changing it never changes what
> anyone else sees, and it does not change the institution's logo or branding.

### 3.5 Notifications

Every role has a **notification bell** (top of the screen) and a full
**Notifications** page.

- The bell shows a count of **unread** items and a short preview list.
- Open the **Notifications** page to see everything, **mark one as read**, **mark
  all as read**, or **delete** an item.
- You only ever see **your own** notifications.
- **Institution Admins** (and a Super Admin working inside a workspace) get an
  extra **Post an announcement** box, which broadcasts an in-app message to
  everyone in that institution. (Meal Managers and Members can read announcements
  but not post them.)

Typical notifications include: a deposit was recorded for you, your claim was
approved or rejected, a member expense claim was approved, and institution
announcements.

---

## 4. Software Super Admin guide

The Software Super Admin (SSA) is a **platform operator**, not a tenant user.
Their navigation is separate from any workspace.

**SSA sidebar**

| Section | Items |
|---|---|
| **Platform Overview** | Business Dashboard · SaaS Analytics · Institution Directory · Trial & Subscriptions · Pricing & Plans · Security & Audit · Landing Enquiries · Broadcasts |
| **Account** | Profile Manager · User Manager · Theme Customizer |
| **Platform Settings** | Role Manager · Global Audit Log · Email Log · Pricing & Plans |

An SSA **does not** get the tenant **Dashboard**, tenant **Analytics**, **Meal
Management**, or the tenant **Claim Review** in their normal navigation. The only
way into an institution's operational screens is to **switch into it** from the
Institution Directory (see 4.3). While switched in, an amber banner reminds you
that you are viewing a workspace, and the tenant's modules become available so you
can help or inspect.

### 4.1 Business Dashboard (the platform control tower)

This is your default landing page. It answers three questions at a glance: *who is
on the platform and how healthy are they, is the business growing, and what
happened across every tenant?*

1. **Headline metrics** — number of institutions, how many are **active**, total
   members, total users, **MRR** (monthly recurring revenue), **ARR** (annualised
   run-rate), average revenue per institution, and counts of **paid / overdue /
   trialing / pending** institutions.
2. **Trials panel** — how many trials are active, **expiring soon**, and already
   **expired**.
3. **Pending enquiries** — demo requests that need action (you can approve one
   straight into a trial from here).
4. **System health** — PHP and Laravel versions, memory usage against the PHP
   limit, the database driver, and platform row volume.
5. **Institution health table** — every institution with its type, active status,
   subscription status, member/vendor/subsidy counts, member-limit usage and a
   health verdict.
6. **Revenue trend** (last 6 months), **throughput** (meals, deposits, expenses
   processed), **growth** (new institutions and new members per month, with a
   month-over-month % chip).
7. **Top institutions** by throughput, and a **recent global activity feed**.

### 4.2 SaaS Analytics (the platform financial engine)

**Platform Overview → SaaS Analytics.** This is about the *SaaS business*, not any
one institution's meal counts.

- **KPIs** — MRR, ARR, **ARPA** (average revenue per paid account), paid/trial/
  expired counts, total institutions, **conversion %**, **churn %**, **revenue at
  risk** (MRR from overdue/pending accounts) and potential ARR.
- **Revenue & growth trend** — a month-by-month series (choose the window, up to
  24 months) of revenue, new institutions, new members and new users.
- **Conversion funnel** — Registered → On trial → Converted → Active paid, plus
  expired trials.
- **Plan breakdown** — which pricing tiers institutions are on, and the MRR each
  contributes.
- **Tenant size distribution** — how big institutions are (member-count buckets) —
  a product-fit signal.
- **Top revenue institutions.**

### 4.3 Institution Directory (the registry)

**Platform Overview → Institution Directory.** Your master list of every
institution on the platform.

- **The table** shows, per institution: name and type, logo, active status,
  creation date, **subscription vs 7-day trial**, a live **trial countdown**,
  active user load, a **health verdict**, and a compact **month summary** (meals,
  expenses, subsidies, deposits, per-meal rate, pool balance).
- **Search** by name or type, and **filter by status** (trial / paid / overdue /
  pending).
- A **totals strip** shows how many institutions, how many active, total members
  and total admins.

**Create a new institution** (the *New Institution* action):

1. Enter the institution **name**, **type** (e.g. University Dorm, Company,
   General Mess), and optional **subtitle**, **address**, **contact email/phone**,
   **currency** and **timezone**.
2. Choose an **onboarding mode**: a **7-day free trial**, or an immediate
   **permanent subscription** (with a plan and monthly amount).
3. Enter the **first Institution Admin's** name, email and password.
4. Save. The platform creates the institution **and its first admin together**
   (so a workspace is never left without an administrator) and emails the admin a
   welcome message containing **both a temporary password and a signed setup
   link**.

**Row actions:**

- **Access Dashboard** — *switches you into* that institution's workspace. This is
  a **session-scoped** switch: it does **not** deactivate any other institution,
  and it does not affect any other user. You land on the workspace's meal roster,
  and the tenant's **Meal Management** and **Workspace Settings** modules appear so
  you can operate inside it.
- **Toggle** — activate or deactivate an institution.
- **Manage subscription** — set the plan, status, amount, renewal date, member
  limit and health notes (this same data feeds the monitoring dashboard).

**Exit the workspace** using the **Return to platform view** action, which clears
the session tenant and takes you back to the global view.

### 4.4 Landing Enquiries (demo-request workflow)

**Platform Overview → Landing Enquiries.** Visitors to the public site can
**Request a demo**, which creates an enquiry here.

- **Stats**: new, contacted, approved, rejected, total.
- **Filter** by status. New enquiries are listed first.
- **Approve** an enquiry to turn it into a live institution:
  1. Confirm or adjust the institution **name** and **type**.
  2. Choose **provision mode** — a **free trial** (default) or a specific
     **plan**.
  3. Optionally set the admin name/password.
  4. Save. The platform provisions the institution, creates its first admin,
     starts the trial (or marks it paid), sends the welcome email to the
     applicant, and links the enquiry to the new institution. If no password was
     supplied, the generated one is shown once.
- **Mark contacted** — records that you have followed up (with an optional note);
  no provisioning happens.
- **Reject** — closes the enquiry with a note.

### 4.5 Subscription Plans (Pricing & Plans)

**Platform Overview → Pricing & Plans.** Define the SaaS tiers and assign them.

- **Create / edit a plan**: name, description, monthly price, **free** flag,
  **trial-default** flag, **member limit** (−1 means unlimited), **manager limit**
  (−1 means unlimited), a **features** list, sort order, and **active**/**public**
  flags. The plan **key** is derived from the name if you leave it blank.
- **Assign a plan** to an institution: pick the institution and the plan. Its
  label, monthly amount, status and member limit update to match.
- **Delete** is refused while any institution is still on that plan — **deactivate
  it instead** (a plan in use is financial configuration and its history must be
  preserved).

### 4.6 Trials & Subscriptions

**Platform Overview → Trial & Subscriptions.** Track who is on a free trial versus
a permanent subscription.

- **Tabs** filter to: all, on trial, ending soon (≤ 2 days), expired, subscribed.
- Each row shows the onboarding mode, subscription label and amount, member count,
  **trial start / end / days left**, and whether a reminder was already sent.
- **Send upgrade prompt** — emails the institution's admin a reminder.
- **Convert to permanent subscription** — set the plan, amount and renewal date;
  all historical data is kept.
- **Extend trial** — add days to the trial window (from the later of now or the
  current end).

### 4.7 Security & Audit (global)

**Platform Overview → Security & Audit.** A centralised, **cross-tenant** view of
everything that happened on the platform, built for compliance.

- Every row carries a **severity**: **Critical** (failures/security), **Warning**
  (deletes/reversals), **Info** (routine writes), **Notice** (logins).
- **Filters**: free-text search, **institution**, **event**, **severity**, and a
  **date range**.
- A summary strip shows severity counts, a **failed-emails** count, and a
  per-institution roll-up.
- **Export** the filtered slice as **Excel** or **PDF**.

The **Global Audit Log** item under **Platform Settings** is the same audit
browser (with the institution filter) for day-to-day "who changed this?" lookups.

### 4.8 Broadcasts (platform announcements)

**Platform Overview → Broadcasts.** The SSA is the only role that can message the
**whole platform** at once (maintenance windows, pricing changes, new features).

1. Write a **title** and **body**.
2. Choose the **audience**: Institution Admins only · All staff (admins +
   managers) · All members · **Everyone on the platform**.
3. Choose a **severity**: Information · Update / Good news · Maintenance /
   Warning · Urgent / Outage.
4. Send. Every recipient gets an in-app notification, and a **history** row records
   exactly what was sent, to whom, and when.

### 4.9 Monitoring & analytics

The **Business Dashboard** (4.1) and **SaaS Analytics** (4.2) together are the
platform's monitoring and analytics suite. Everything here is intentionally
cross-tenant — that is the SSA's job — and nowhere else in the app lifts tenant
isolation.

### 4.10 Platform settings (User Manager, Role Manager, Email Log)

- **User Manager** (Account → User Manager) — in your hands this is a **global
  directory**: it lists users across **every** institution (plus platform
  accounts). You can filter by institution and create a user **directly against
  any chosen institution** without switching in first.
- **Role Manager** (Platform Settings → Role Manager) — roles and permissions are
  **platform reference data** (one shared catalogue for the whole platform, not
  per institution). Create/edit a role and tick the permissions it grants
  (grouped by module). **The Super Admin role cannot be deleted.**
- **Email Log** (Platform Settings → Email Log) — every email the platform has
  dispatched, across all institutions, with status (sent/failed/pending). You can
  open a row to see the **full rendered email** the recipient received.

---

## 5. Institution Admin guide

The Institution Admin runs **one institution**. Everything you see is inside your
own workspace; you can never reach another institution's data.

**Institution Admin sidebar**

| Section | Items |
|---|---|
| **Workspace Overview** | Dashboard · Analytics |
| **Meal Management** | Members · Subsidies · Departments · Deposits · Meal Entries · Expenses · Vendors · Meal Reports · Claim Review |
| **Account** | Profile Manager · User Manager · Theme Customizer |
| **Workspace Settings** | Institution · Invite Code · Currency Manager · Subsidy Sources · Activity Log · Email Log |

### 5.1 Dashboard

Your home page shows the **whole institution's pooled picture** (not just your own
figures):

- **Pool financials** — pool balance (total deposits − total expenses), total
  deposits and expenses, and this month's deposits and expenses.
- **Meal metrics** — meals this month, this week and today; this month's meal cost;
  the current **per-meal rate**.
- **Roster** — active members, total members, **members with dues**, and **total
  dues**.
- **Charts** — a 14-day meals + spend trend, and **spend by category** for the
  month.
- **Top members** by meals, and a **recent transactions** feed.
- **Reconciliation notice** — if deposits recorded in the meal module ever drift
  from the money ledger, the dashboard flags the gap rather than silently picking
  one number.

### 5.2 Institution settings (identity, terminology, currency, branding)

**Workspace Settings → Institution.**

1. **Identity** — set the **name**, **subtitle**, **type** (e.g. Company, Uni Dorm,
   College Dorm, General Mess), **address**, **contact email** and **contact
   phone**, **currency code** and **timezone**.
2. **Terminology** — override any label so the app speaks your institution's
   language. A dorm might call members **Students**, a company **Employees**;
   likewise "department" vs "group", and the manager title. Leave a field blank to
   use the preset that comes from your chosen type. Overrides apply across the
   entire workspace instantly.
3. **Branding** — upload a **logo** and a **banner** (PNG, JPG, SVG or WebP);
   remove either at any time.
4. Save.

> **Theme customisation is *not* here.** It lives in **Theme Customizer**, because
> it is a per-person preference. Institution settings cover **identity,
> terminology, currency and branding** only.

**Currency Manager** (**Workspace Settings → Currency Manager**) is where you set
how money is formatted for your workspace: currency code, symbol and its position,
decimal and thousands separators (they must differ), decimal precision, **numbering
system** (short / long / Indian / East Asian), and whether large numbers are
abbreviated (with the threshold at which that kicks in). Once saved, **every
screen, report and export uses this format**.

### 5.3 Invite codes (letting people join)

**Workspace Settings → Invite Code.** The invite code is the key a member types on
the public sign-up form to join *your* institution.

1. **View** your workspace's current code.
2. **Copy the ready-to-share sign-up link** — it opens the registration page with
   your code already filled in.
3. **Rotate (regenerate)** the code when needed — this **revokes all previously
   shared links** (the old code stops working). Rotation is recorded, and the old
   and new codes are kept in the activity log.
4. The screen also shows how many members your institution has.

### 5.4 User management & roles

**Account → User Manager.** Everyone who can sign in to your institution is listed
here (you will never see the platform's Super Admin accounts).

**Create a user** — choose one of two modes:

- **Send invitation** — sends the person a signed link so they set their **own**
  password. This is the preferred mode.
- **Set a temporary password** — you type a password and the user is **forced to
  change it on first sign-in**. Use this when email/SMTP is unavailable.

Then assign a **role**: **Institution Admin**, **Meal Manager**, or **Member**.
You can **edit** a user, **activate/deactivate** them (deactivating keeps their
history but blocks sign-in), or **delete** them. You cannot create or grant the
global Super Admin role, and you cannot deactivate or delete your own account.

**Role definitions** (which permissions each role holds) are managed **platform-
wide** by the Software Super Admin, not per institution.

### 5.5 Members (the roster)

**Meal Management → Members** is your roster.

- **Add a member** with the *Add Member* button: name, **Roll ID**, department,
  status, and optionally which **manager** is responsible for them. No password is
  set here.
- **Invite** a member (the *Invite* action on their row) to email them a secure
  link; they choose their own password and their login is linked to the member
  record.
- Every member has a **Roll ID** — that is the identifier shown everywhere, not an
  internal database id.
- **Managed by** records which manager is responsible for that member's record.
- The table shows, **for the selected month**: meals eaten, meal cost, money
  deposited and the resulting **balance**. Use the month selector at the top-left
  to look at any month.
- **Export** the roster (Excel or PDF) from the toolbar.
- **Remove** a member — blocked if they have any meal or deposit history (that is
  financial history). **Mark them inactive** instead.

> **Balance colours:** green means the member has credit; red means they still owe
> the pool.

### 5.6 Departments

**Meal Management → Departments.** Departments group members for reporting and bulk
operations. Add, edit or delete a department. A department that still has members
**cannot be deleted** until you reassign them. (The label "department" can be
renamed via terminology — the underlying concept is unchanged.)

### 5.7 Meal entries (recording meals)

**Meal Management → Meal Entries → Record Meals.**

1. Pick the **date**. A panel shows *Selected Date Meal* — what is already recorded
   for that day, broken into breakfast / lunch / dinner.
2. Fill in each member's meals. Use the **0** / **1** buttons above a column to set
   everyone at once (the common case: everyone ate lunch).
3. Press **Save**. Any row left at zero is removed, and any row you are not allowed
   to edit is silently skipped.

The **list view** shows recorded entries for a date (with a filter by date and
member) and that day's totals.

### 5.8 Deposits (money in)

**Meal Management → Deposits.** A deposit is **money a member pays into the pool**.
It is also recorded automatically as a **cash-in** transaction so the two can never
disagree.

- **Record Deposit** — choose the member, enter the amount and payment method, set
  the **type** (*Personal* or *Subsidy*), and add notes.
- **Edit** a deposit (the amount stays in sync with its ledger entry; the member is
  only re-notified if the amount actually changed).
- **Reverse** a deposit — the original row is kept for history but flagged, and a
  matching cash-out is posted so the books balance.
- **Filter** by member, type (personal/subsidy), and date range; **search** by
  member, method or note.
- **Totals** are split so subsidy money is never counted as a personal
  contribution: filtered total, personal total, subsidy allocated, and subsidy
  grants recorded at source.
- **Export** the ledger as **Excel** or **PDF**.

> **Cash In = Deposits.** There is no separate "Add Transaction" screen — that
> concept was merged into this module.

### 5.9 Expenses (money out)

**Meal Management → Expenses.** An expense is **money the mess spends**. It is also
recorded automatically as a **cash-out** transaction.

- **Record Expense** — enter the amount, a description and a **category**; **link a
  vendor** so recurring shopping is attributed to the right supplier; set the
  **payment status** (*paid*, *unpaid* or *partial*) and any notes.
- **Edit** an expense (a **reversed** expense cannot be edited — record a new one
  instead).
- **Reverse** an expense — the row is kept but flagged, and a matching cash-in is
  posted so the money returns to the books.
- **Filter** by category, vendor and month; **search** by description/category/
  vendor.
- The **Spend by Vendor This Month** panel shows where the money went.
- Anything marked **unpaid** counts toward that vendor's **outstanding balance**.

> **Cash Out = Expenses.** Same reasoning as deposits.

### 5.10 Subsidies (institutional funding)

**Meal Management → Subsidies.** A subsidy is money injected by an **authority**
rather than by a member, tracked separately so a member's own contributions are
never confused with institutional funding.

1. Click **Record Subsidy**.
2. Choose the **funding source**, the **amount**, an optional **percentage** (the
   share of the pool that funder is expected to cover), and the **period (month)**.
3. Choose how it is applied:
   - **Into the common pool** — adds to everyone's shared fund.
   - **Split per active member** — distributed evenly across the roster (remainder
     cents go to the earliest member so the total always matches exactly).
   - **Reserve (applied after member funds)** — held back and only drawn on once a
     member's own deposits are exhausted (the **strict balance rule**).
4. Save. The money enters the ledger as a cash-in, tagged distinctly from personal
   deposits.

- **Reverse** a subsidy (e.g. a grant overpaid or withdrawn) — the row is kept but
  flagged, and any per-member deposit rows it created are removed.
- Subsidies are filtered **strictly by month**, not arbitrary date ranges.
- Totals are split by apply mode (pool / per-member / reserve) and by source.

**Subsidy Sources** (**Workspace Settings → Subsidy Sources**) is where you add the
bodies that fund your meals (for example "University Authority", 50%). These appear
on the Record Subsidy form. A source that is already referenced by a subsidy
**cannot be deleted** — **deactivate** it instead; it disappears from the form but
keeps its history.

### 5.11 Vendors

**Meal Management → Vendors.** A vendor is anyone you buy from. Your institution
itself appears first, marked **Hub** — it is the primary supplier in the ecosystem.

- Add a vendor with its **category**, **contact details**, an **opening balance**
  (what you already owed them when onboarding) and a **recurrence** (daily, weekly,
  monthly, or on demand). Recurring vendors can carry a **lead time** and a typical
  **recurring amount**.
- Each row shows the vendor's **outstanding balance** — opening balance plus
  everything bought on credit and not yet marked paid.
- Open a vendor's **purchase history** to see its orders, total purchased and
  outstanding balance.
- **Export** the vendor ledger as **Excel** or **PDF**.
- A vendor with purchase history **cannot be deleted** — mark them **inactive**
  instead.

### 5.12 Reports

**Meal Management → Meal Reports** defaults to the **current month**.

- **Summary cards**: total meals (split into breakfast/lunch/dinner), total
  deposits, subsidies, expenses, meal cost, the **per-meal rate**, the **pool
  balance**, and the **subsidy coverage %**.
- The **per-member table** shows each member's meals, meal cost, deposits, subsidy
  share and balance.
- Tick **Only show dues** to see just the members who owe money, with the total
  owed.
- **Export Excel** or **Export PDF** for exactly the figures on screen.

### 5.13 Exports

Every financial and meal report offers:

- **Excel** — opens in Excel, LibreOffice or Google Sheets, with numbers stored as
  numbers so you can sum them.
- **PDF** — opens a print-ready page; use your browser's *Save as PDF*.

Available exports include the member roster, the deposit ledger, the vendor ledger,
and the meal report. Every export is recorded in the activity log (who pulled what
data).

### 5.14 Activity log (audit trail)

**Workspace Settings → Activity Log.** Every create, update and delete — plus
sign-ins, invitations and exports — is recorded with **who** did it, **what**
changed, and **when**. You see **only your own institution's** activity; the scope
is forced from your account, so it cannot be widened with a filter. Use the search,
event, module and actor filters to narrow the feed. Use it to answer "who changed
this?" without asking around.

### 5.15 Email log

**Workspace Settings → Email Log** is your institution's **outbox**: every email the
platform sent on your institution's behalf (invitations, welcome messages, upgrade
reminders, etc.). Filter by status (sent/failed/pending), kind, and search by
recipient/subject. Open a row to see the **full rendered email** as the recipient
received it.

### 5.16 Claims review

**Meal Management → Claim Review.** Members raise two kinds of claim — a **dispute**
(a deposit is missing, or a meal was not counted) or an **expense** (a member bought
supplies and wants reimbursing). You are their reviewer.

- The **pending / approved / rejected** counts sit at the top; pending claims are
  listed first.
- **Approve** a claim — this is the **only** point at which money moves, and it
  happens safely (the ledger and the member's balance update together). You may
  approve a **different amount** than claimed (e.g. a partial reimbursement).
  - A **dispute** approval creates the missing deposit or meal entry (or a credit
    adjustment).
  - An **expense** approval records a reimbursable cash-out and credits the member
    with what they spent.
- **Reject** a claim with an optional note. No money moves.
- The member is notified of the decision either way.

---

## 6. Meal Manager guide

The Meal Manager runs the **day-to-day operations**. You work inside one
institution, and **only with the members assigned to you**.

**Meal Manager sidebar**

| Section | Items |
|---|---|
| **Workspace Overview** | Dashboard · Analytics |
| **Meal Management** | Members · Subsidies · Departments · Deposits · Meal Entries · Expenses · Vendors · Meal Reports · Claim Review |
| **Account** | Profile Manager · Theme Customizer |
| **Workspace Settings** | Institution · Invite Code · Currency Manager · Activity Log · Email Log |

**What you do *not* see** (and why):

- No **User Manager** — creating and revoking logins stays with the Institution
  Admin.
- No **Subsidy Sources** — the funding bodies are admin-configured.
- No **Role Manager** — role definitions are platform-level.

**Read-only** screens: you can **view** Institution settings, the Invite Code,
the Currency Manager, the Activity Log and the Email Log, but you cannot change
them (those writes belong to the Institution Admin and the Super Admin).

### 6.1 Your scope: the members assigned to you

The most important thing to understand as a manager is **assignment**. A member's
record names a **manager** (its "managed by"). You are limited to **your assigned
members** on the operational screens that deal with individual people:

- **Members** — you only see the members assigned to you; you cannot open another
  manager's member.
- **Meal Entries** — the daily grid and the list only contain your assigned
  members.
- **Deposits** — you only see (and can only record) deposits for your assigned
  members.
- **Claim Review** — you only see claims raised by **your** members; the queue
  tells you when it is showing "only your assigned members."

Other operational screens — **Expenses**, **Vendors**, **Subsidies**, **Departments**
and **Meal Reports** — show your institution's data as a whole, because they are
not about one member's individual record.

### 6.2 What you can do

- **Dashboard** — the institution's pooled overview (5.1). *Analytics* — the
  institution's trends and forecast (see below).
- **Members** — add a member, edit, invite a member (email them a secure
  password-setup link), view their month figures and detail, and view their
  deposits and recent meals. (Removing a member entirely is an admin action.)
- **Departments** — add, edit and delete the groups (a group with members cannot be
  deleted until they are reassigned).
- **Meal Entries** — record the day's meals for your members (5.7).
- **Deposits** — record and edit deposits for your members, and reverse a mistake
  (5.8).
- **Expenses** — record and edit expenses, link vendors, set payment status,
  reverse a mistake (5.9).
- **Vendors** — add, edit and view vendors and their purchase history (5.11).
- **Subsidies** — **view** institutional funding (who funded what, and how it is
  applied). Recording and reversing subsidies is an admin action.
- **Meal Reports** — the month report and its **Excel / PDF** exports (5.12).
- **Claim Review** — review, approve and reject claims from your members (5.16).
- **Notifications** — read institution announcements (you cannot post them).

### 6.3 Analytics & the forecast

**Analytics** shows your institution's trends:

- **Summary cards** — deposits, expenses, net savings, subsidies. The up/down
  arrow beside a number is the change versus the previous period.
- **Subsidy tracking** — each funder's target share against what was actually
  recorded this month.
- **3-month predictive forecast** — projects next month's meals and cost from the
  recent trend, then calculates the **subsidy funding required** to hold your
  target ratio.

**Understanding the forecast:** the system learns from the last three months,
weighting recent months more heavily, and clamps the growth rate so one unusual
month cannot produce a wild projection. It then applies your **target ratio** — by
default the **80/20 rule**: members cover 80% of the meal cost, subsidies the
other 20%. The "Subsidy Needed" figure is the exact funding required next month to
keep that split — the number to bring to a grant negotiation.

---

## 7. Member guide

As a member you see **only your own** meals, money and balance — never another
member's, and never the institution's pooled totals.

**Member sidebar**

| Section | Items |
|---|---|
| **My Account** | Summary · Meal Entries · Deposits · Analytics · My Claims |
| **Account** | Profile Manager · Theme Customizer |

### 7.1 My Summary (your personal dashboard)

**My Account → Summary** is your merged personal view:

- **Balance** — green means you have credit; red means you still owe the pool.
- **This month**: meals eaten, meal cost, amount deposited, and any **subsidy
  share** credited to you.
- **Lifetime**: total deposits, total meals, and an estimate of lifetime meal cost
  (at the current rate — labelled as an estimate).
- The **current per-meal rate**.
- A **month-by-month history** table (up to 12 months) of your meals, cost,
  deposits and balance, so you can see the trend.
- **Recent meal entries** and **recent deposits**.
- **Your claims** with their statuses, and a count of any still pending.

Use the **month selector** to look back at any month.

### 7.2 My Meals

**My Account → Meal Entries.** Your own meal entries, day by day, with a summary
strip of breakfast / lunch / dinner / total for the selected month. Choose a month
to look back.

### 7.3 My Deposits

**My Account → Deposits.** Every payment recorded against you, with a running
**total deposited**. Each row shows the amount, method, type and any note. A
reversed deposit is shown as such but kept for your records.

### 7.4 My Balance

Your **balance** is shown prominently on **Summary** (green = credit, red = due).
It is simply **what you have deposited minus what your meals cost**, after any
subsidy share credited to you.

### 7.5 My Claims (raise and track)

**My Account → My Claims.** If something is wrong, or you spent your own money for
the mess, raise a **claim**:

- Choose the **kind**:
  - **Dispute** — for example, a deposit is missing, or a meal you ate was not
    counted. For a meal dispute, pick the date and the missed meals.
  - **Expense** — you bought supplies and want to be reimbursed. Enter the amount
    you spent.
- Give it a **title** and, optionally, a **description**.
- Submit. Your manager (and admins, as a safety net) are notified.

You can then **track** each claim's **status** (pending, approved, rejected) and
read the **reviewer's notes** on the same screen.

> If your login is not yet linked to a member record, you cannot raise claims yet —
> ask your manager to link it.

### 7.6 My Analytics

**My Account → Analytics.** Your own monthly trend (up to 12 months): meals, meal
cost, deposits and balance, plus a breakdown of your meals (breakfast/lunch/
dinner) for the selected month. This is deliberately *your* data — never the
institution's pooled figures.

### 7.7 What members cannot do

Members cannot reach **any** administrative module: no roster, no deposits screen
for others, no expenses, no subsidies, no reports, no user management. If you need
something beyond your own records, raise a claim or contact your manager.

---

## 8. Common tasks — quick reference

| I want to… | Go to | Who |
|---|---|---|
| Sign in | Log in page | Everyone |
| Set up my account from an invite email | The link in the email → set password | Everyone |
| Create an account with an invite code | Register page → enter invite code | Public |
| Reset a forgotten password | Log in → Forgot password | Everyone |
| Edit my name / email / picture | Profile Manager (or your name in the sidebar footer) | Everyone |
| Change my password | Change Password | Everyone |
| Change the look (light/dark, accent, font) | Account → Theme Customizer | Everyone |
| Read my notifications | The bell, or Notifications | Everyone |
| Post an announcement to my institution | Notifications → Post an announcement | Institution Admin (and SSA in a workspace) |
| Add someone to the roster | Meal Management → Members → Add Member | Admin, Meal Manager |
| Give a new member a login | Members → Invite (on their row) | Admin, Meal Manager |
| See who owes money | Meal Management → Meal Reports → tick "Only show dues" | Admin, Meal Manager |
| Record who ate today | Meal Management → Meal Entries → Record Meals | Admin, Meal Manager |
| Take a member's payment | Meal Management → Deposits → Record Deposit | Admin, Meal Manager |
| Fix a wrong deposit | Meal Management → Deposits → Reverse | Admin, Meal Manager |
| Log a grocery purchase | Meal Management → Expenses → Record Expense | Admin, Meal Manager |
| Link a purchase to a supplier | Expenses → Record/Edit → Vendor | Admin, Meal Manager |
| Add a supplier | Meal Management → Vendors → Add Vendor | Admin, Meal Manager |
| Record a grant / subsidy | Meal Management → Subsidies → Record Subsidy | Admin (managers may view) |
| Add a funding body | Workspace Settings → Subsidy Sources | Admin |
| Add or edit a department/group | Meal Management → Departments | Admin, Meal Manager |
| Predict next month's cost | Analytics → 3-Month Predictive Forecast | Admin, Meal Manager |
| Set institution name/type/terminology | Workspace Settings → Institution | Admin |
| Set the currency format | Workspace Settings → Currency Manager | Admin (managers view only) |
| Let members self-sign-up | Workspace Settings → Invite Code → share link | Admin |
| Revoke a shared sign-up link | Invite Code → Regenerate | Admin |
| Create a user / change roles | Account → User Manager | Admin |
| Find out who changed something | Workspace Settings → Activity Log | Admin, Meal Manager |
| See what emails were sent | Workspace Settings → Email Log | Admin, Meal Manager |
| Review member claims | Meal Management → Claim Review | Admin, Meal Manager |
| Raise or track a claim | My Account → My Claims | Member |
| See my own meals / deposits / balance | My Account → Summary / Meal Entries / Deposits | Member |
| Export a roster or report | Any report/roster → Export Excel / PDF | Admin, Meal Manager |
| **Platform:** see the whole business | Platform Overview → Business Dashboard | Software Super Admin |
| **Platform:** see SaaS revenue & conversion | Platform Overview → SaaS Analytics | Software Super Admin |
| **Platform:** list every institution | Platform Overview → Institution Directory | Software Super Admin |
| **Platform:** create a new institution | Institution Directory → New Institution | Software Super Admin |
| **Platform:** work inside one institution | Institution Directory → Access Dashboard | Software Super Admin |
| **Platform:** leave a workspace | Return to platform view (amber banner) | Software Super Admin |
| **Platform:** action a demo request | Platform Overview → Landing Enquiries | Software Super Admin |
| **Platform:** define pricing tiers | Platform Overview → Pricing & Plans | Software Super Admin |
| **Platform:** track trials | Platform Overview → Trial & Subscriptions | Software Super Admin |
| **Platform:** message everyone | Platform Overview → Broadcasts | Software Super Admin |
| **Platform:** audit the whole platform | Platform Overview → Security & Audit | Software Super Admin |
| **Platform:** manage role definitions | Platform Settings → Role Manager | Software Super Admin |
| **Platform:** list users across institutions | Account → User Manager | Software Super Admin |

---

## 9. Troubleshooting & FAQ

**"No rate" shows instead of a balance.**
No expenses or meals have been recorded for that month yet, so there is no
per-meal rate. Record an expense and some meals, and the rate (and the balances
that depend on it) will appear.

**A member cannot be deleted.**
They have meal or deposit history — that is financial history and must be kept.
Mark them **Inactive** instead; they stop appearing on entry forms but their
records stay intact.

**A department cannot be deleted.**
It still has members assigned. Reassign those members to another department first.

**A subsidy source cannot be deleted.**
Subsidies already reference it. Deactivate it instead — it disappears from the
Record Subsidy form but keeps its history.

**A vendor cannot be deleted.**
They have purchase history. Mark them **Inactive** instead so past expenses stay
intact and reports still line up.

**A pricing plan cannot be deleted.**
Institutions are still on it. **Deactivate** the plan instead; this preserves the
history of the institutions whose stored plan is that one.

**The forecast looks flat.**
There is not enough history yet. It needs a few weeks of meals and expenses before
the trend becomes meaningful.

**My invite / setup link does not work.**
A setup link is **single-use** and **time-limited**. If it has already been used,
or has expired, ask an admin to send a new one. A tampered link is rejected
automatically.

**I get "Your account is not linked to a member record yet."**
Your login exists but is not yet attached to a member (roster) record, so you have
no meals or deposits to show. Ask your manager to link your account.

**I was given a temporary password and keep being asked to change it.**
That is by design: a temporary password must be replaced before you can use the
system. Set a new password on the **Change Password** screen and you will be let
through.

**My whole institution seems empty / I can't see another institution's data.**
Institutions are fully isolated. An Institution Admin or Meal Manager only ever
sees their own institution's data — that is the platform's core safety guarantee,
not a bug. Only a Software Super Admin can move between workspaces.

**I'm a Super Admin but I only see platform screens, not meal sheets.**
That is intentional: the Super Admin starts on the **platform** view. To work
inside one institution, open the **Institution Directory** and use **Access
Dashboard** to switch in; a banner will show you are in a workspace, and an exit
action returns you to the platform view.

**The theme I set isn't applying.**
Theme is saved to your account and applies instantly on the device you set it on,
and again on any other device you sign in from. If it looks off, open **Theme
Customizer** and press **Reset** to return to the platform default, then re-apply.

**A member's name in the roster differs from their login name.**
Names are kept in sync automatically: editing your name in **Profile Manager**
updates the roster, and editing the roster updates the login. If you ever see a
mismatch, edit the name once on either side and it will realign.

**An "Add Transaction" bookmark no longer shows a form.**
The standalone Add Transaction screen was retired: **Cash In is a Deposit** and
**Cash Out is an Expense**. Old bookmarks redirect to the correct module; use
**Deposits** or **Expenses** instead.

**I can't create users.**
Only an **Institution Admin** or a **Software Super Admin** can manage users. A
**Meal Manager** has day-to-day operations but no user management — ask your admin.

**I can't post an announcement.**
Only an **Institution Admin** (or a Super Admin inside the workspace) can post
announcements. Managers and members can read them.

**The Super Admin role can't be deleted.**
Correct — it is protected so the platform owner can never be locked out. Deleting
or deactivating the last active Super Admin is also blocked.

---

*This manual covers the current build. Where a screen's available actions depend on
your role, use the sidebar as the source of truth: the menu shows exactly what you
are allowed to reach.*
