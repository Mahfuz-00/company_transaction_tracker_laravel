# User Manual

A practical guide to running a meal & expense program on this system. Written
for the people who actually use it day to day: the institution admin, the meal
manager, and the member.

---

## 1. What this system does

It tracks **shared meal money**. Members pay money in (deposits), the mess
spends money out (expenses), members eat meals, and the system works out who
owes what — plus how much subsidy an authority has injected to help cover the
cost.

The one number everything revolves around:

```
per-meal rate = total expense ÷ total meals
```

If the mess spent **2,288.12** this month and members ate **97** meals, the
rate is **23.59 per meal**. Multiply that by the meals a member ate to get
what they owe.

---

## 2. Roles

There are exactly **four** roles.

| Role | What they can do |
|---|---|
| **Software Super Admin** | Everything, everywhere. Owns platform settings (currency, institutions). Sees the Institution Registry and every audit trail. |
| **Institution Admin** | Runs one institution: members, meals, deposits, expenses, subsidies, vendors. Can customise terminology and theme. |
| **Meal Manager** | Day-to-day operations: records meals, deposits and expenses. Read-only on subsidies and settings. |
| **Member** | Sees their own meals and balance only. |

---

## 3. Getting started (first-time setup)

1. **Sign in** with your admin account.
2. **Settings → Institution** — set the name, subtitle, type (Company, Uni Dorm,
   College Dorm, General Mess), currency and timezone.
3. **Customise terminology** — in the same screen, override any label. A dorm
   might call members "Students", a company "Employees". Leave a field blank to
   use the preset.
4. **Customise the theme** — pick an accent colour; it applies instantly to the
   whole workspace.
5. **Settings → Subsidy Sources** — add the bodies that fund your meals
   (e.g. "University Authority", 50%). These appear on the Record Subsidy form.
6. **Vendors** — add your suppliers. The institution itself already appears as
   the primary **hub** vendor.

---

## 4. Members

**Meals → Members** is your roster.

- **Add a member** with the *Add Member* button. No password is set here.
- **Invite** a member (the *Invite* action on their row) to email them a secure
  link. They choose their own password — the system never issues a default one.
- Every member has a **Roll ID** — that is the identifier shown everywhere, not
  the internal database id.
- **Managed By** records which manager is responsible for that member's record.

The table shows, **for the selected month**: meals eaten, meal cost, money
deposited and the resulting balance. Use the month selector at the top-left to
look at any month.

> **Balance:** green means the member has credit; red means they still owe the
> pool.

---

## 5. Recording meals

**Meals → Meal Entries → Record Meals**

1. Pick the **date**. The panel on the right shows *Selected Date Meal* — what
   is already recorded for that date, broken into breakfast/lunch/dinner.
2. Fill in each member's meals. Use the **0** / **1** buttons above a column to
   set everyone at once (the common case: everyone ate lunch).
3. Press **Save**. Any row left at zero is removed.

---

## 6. Money in — Deposits

**Meals → Deposits**

A deposit is **money a member pays into the pool**. It is also recorded as a
"Cash In" transaction automatically.

- Click **Record Deposit**, choose the member, type the amount and method.
- The **Type** column distinguishes *Personal* deposits from *Subsidy* money.
- Use the month selector and the type/vendor filters to narrow the list.
- Export the ledger as **Excel** or **PDF** from the top-right.

> **Cash In = Deposits.** There is no separate "Add Transaction" screen — that
> concept was merged into this module so the two can never disagree.

---

## 7. Money out — Expenses

**Meals → Expenses**

An expense is **money the mess spends**. Also recorded as a "Cash Out"
transaction.

- Record the amount, description and category.
- **Link a Vendor** so recurring shopping is attributed to the right supplier.
- Set **Payment Status** — anything marked *unpaid* counts toward that vendor's
  outstanding balance.
- The *Spend by Vendor This Month* card shows where the money went.

> **Cash Out = Expenses.** Same reasoning as deposits.

---

## 8. Institutional subsidies

**Meals → Subsidies**

This is money injected by an authority — a university, company or college —
rather than by a member. It is tracked **separately** so a member's own
contributions are never confused with institutional funding.

- Click **Record Subsidy**.
- Choose the **funding source**, the amount, and the **period (month)**.
- **Funding percentage** is the share of the pool that funder is expected to
  cover (e.g. 20).
- **How it is applied:**
  - *Into the common pool* — adds to everyone's shared fund.
  - *Split per active member* — distributed evenly across the roster.
  - *Reserve (applied after member funds)* — held back, and only used once a
    member's own deposits are exhausted. This is the **strict balance rule**.

Subsidies are filtered strictly by **month**, not arbitrary date ranges.

---

## 9. Reports

**Meals → Meal Reports** defaults to the **current month**.

- Summary cards: total meals, deposits, subsidies, expenses, pool balance.
- The per-member table shows meals, meal cost, deposits and balance.
- Tick **Only show dues** to see who owes money.
- **Export Excel** or **Export PDF** for the same figures you see on screen.

---

## 10. Analytics & the forecast

**Analytics** shows trends and predicts the future.

- **Summary cards** — deposits, expenses, net savings, subsidies. The up/down
  arrow next to a number is the change versus the previous period.
- **Subsidy Tracking** — each funder's target share against what was actually
  recorded this month.
- **3-Month Predictive Forecast** — projects next month's meals and cost from
  the last three months' trend, then calculates the **subsidy funding required**
  to hold your target ratio.

### Understanding the forecast

The system learns from the last three months, weighting recent months more
heavily, and clamps the growth rate so one unusual month cannot produce a wild
projection. It then applies your **target ratio** — by default the **80/20
rule**: members cover 80% of the meal cost, subsidies the other 20%.

> The "Subsidy Needed" figure is the exact funding required next month to keep
> that split. If you negotiate a grant, this is the number to bring.

---

## 11. Vendors

**Meals → Vendors**

The institution itself appears first, marked **Hub** — it is the primary
supplier in the ecosystem. Add other vendors with their category, contact
details and **recurrence** (daily, weekly, monthly, on demand). Recurring
vendors can carry a lead time and a typical order value.

---

## 12. Exports

Every financial and meal report offers:

- **Excel** — opens in Excel, LibreOffice or Google Sheets, with numbers stored
  as numbers so you can sum them.
- **PDF** — opens a print-ready page; use your browser's *Save as PDF*.

---

## 13. Activity log (audit trail)

**Settings → Activity Log**

Every create, update and delete — plus sign-ins, invitations and exports — is
recorded here with **who** did it, **what** changed (old → new values) and
**when**. Super Admins see every institution; Institution Admins see their own.

Use it to answer "who changed this?" without asking around.

---

## 14. Profile & images

**Click your name in the sidebar footer** to open your profile. Upload a
profile picture, update your name, email and designation. Your picture appears
in the sidebar and beside your activity in the audit log.

---

## 15. Common tasks, quickly

| I want to… | Go to |
|---|---|
| Add someone to the roster | Meals → Members → Add Member |
| Give a new member a login | Members → Invite (on their row) |
| Record who ate today | Meals → Meal Entries → Record Meals |
| Take a member's payment | Meals → Deposits → Record Deposit |
| Log a grocery purchase | Meals → Expenses → Record Expense |
| Record a university grant | Meals → Subsidies → Record Subsidy |
| See who owes money | Meals → Reports → tick "Only show dues" |
| Predict next month's cost | Analytics → 3-Month Predictive Forecast |
| Find out who changed something | Settings → Activity Log |

---

## 16. Troubleshooting

**"No rate" shows instead of a balance.**
No expenses or meals have been recorded for that month yet, so there is no
per-meal rate. Record an expense and some meals.

**A member cannot be deleted.**
They have meal or deposit history — that is financial history and must be kept.
Mark them **Inactive** instead; they stop appearing on entry forms but their
records stay intact.

**A subsidy source cannot be deleted.**
Subsidies already reference it. Deactivate it instead — it disappears from the
form but keeps its history.

**The forecast looks flat.**
There is not enough history yet. It needs a few weeks of meals and expenses
before the trend becomes meaningful.
