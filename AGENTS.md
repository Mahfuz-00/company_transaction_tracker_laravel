# DATABASE PROTECTION RULE — READ FIRST, OBEY ALWAYS

> **STOP. Before you run ANY of these:**
> `php artisan test`, `php artisan dusk`, `php artisan migrate`, `php artisan db:seed`,
> `migrate:fresh`, `migrate:refresh`, `db:wipe`, `php artisan tinker` with writes,
> or any script that inserts/updates/deletes rows —

---

## THE RULE

**No test, seeder, migration, or reset command may EVER run against the developer's
database or any database holding real data.**

The developer's database is `database/database.sqlite`, configured in **`.env`**.

You must **never** truncate, wipe, drop, refresh, re-seed, or overwrite it. Not to
"get a clean slate". Not to make a test pass. Not with `--force`. Not ever.

---

## WHY THIS RULE EXISTS (a real incident, not a hypothetical)

The Dusk suite **truncates every table it touches between tests**. That is correct
and necessary behaviour — tests must start from a known state.

The danger is that it is *indiscriminate*. It does not know which database it is
pointed at. It simply deletes rows.

In this repository, `.env` was previously configured as:

```ini
DB_CONNECTION=sqlite
DB_DATABASE=database/dusk.sqlite      # <-- the TEST database
```

So `php artisan dusk` truncated the developer's working database on **every run**.
Symptoms that looked like application bugs and were actually this:

- `admin@mahfuz.com` "losing" its password and role,
- institutions and members disappearing overnight,
- features that worked in the morning being empty by the afternoon,
- `php artisan db:seed` being run repeatedly, because the data kept vanishing.

Nobody noticed, because the failure is silent and the cause is in a config file
rather than the code being tested.

**The fix, which must be preserved:** the dev database and the test database are
now **different files**.

| File | Purpose | May be destroyed? |
|---|---|---|
| `database/database.sqlite` | **DEVELOPMENT** — the developer's working data | **NEVER** |
| `database/testing.sqlite` | **TEST ONLY** — Dusk + any browser test | Yes. Delete it freely. |

---

## HOW TO RUN TESTS SAFELY

The test database is selected by **environment file**, not by a flag:

| File | `DB_DATABASE` | Used by |
|---|---|---|
| `.env` | `database/database.sqlite` | The running app. **Never a test.** |
| `.env.testing` | `database/testing.sqlite` | `php artisan test`, PHPUnit |
| `.env.dusk.local` | `database/testing.sqlite` | `php artisan dusk` |

```bash
# Correct: writes to database/testing.sqlite only.
php artisan dusk

# Correct: if you need a clean test database, reset the TEST file explicitly.
php artisan migrate:fresh --env=testing --database=sqlite
```

```bash
# FORBIDDEN — would run against .env, i.e. the DEVELOPMENT database:
php artisan migrate:fresh
php artisan db:wipe
php artisan migrate:fresh --seed
php artisan db:seed --class=MockDataSeeder    # without --env=testing
```

### Mandatory pre-flight check

Before running anything destructive, **print the target and confirm it**:

```bash
php artisan tinker --execute="echo config('database.connections.sqlite.database');"
```

If it prints `database/database.sqlite`, **STOP**. That is the dev database.
Switch to `.env.testing` first.

---

## BUILT-IN SAFEGUARD — DO NOT WEAKEN IT

`tests/Browser/Support/DuskDatabase.php` contains `assertDuskDatabaseIsDisposable()`,
which runs **before** any migration or truncation and **throws** unless the
configured database is a known disposable test file.

It is an **allow-list**, not a suffix check — a filename that merely *looks* like a
test database is still refused:

```php
$allowed = ['testing.sqlite', ':memory:'];
```

It additionally refuses `database/database.sqlite` by name.

**If a test fails with "REFUSING TO RUN", the fix is to correct the environment
file. It is NEVER to relax this guard.** Weakening it re-opens the exact bug it
was written to close.

---

## RULES FOR AI CODING AGENTS (Cursor, Copilot, Windsurf, Claude, etc.)

1. **Never** run `migrate:fresh`, `db:wipe`, `migrate:refresh`, or a destructive
   seeder without `--env=testing`.
2. **Never** edit `.env` to point `DB_DATABASE` at a test file.
3. **Never** edit `.env.testing` / `.env.dusk.local` to point at `database.sqlite`.
4. **Never** remove or weaken `assertDuskDatabaseIsDisposable()`.
5. **Never** commit a `.sqlite` file. They are git-ignored; keep them that way.
6. **Never** "fix" a test by resetting the developer's data.
7. If you need fixtures, create them in the **test** database inside the test.
8. If a task seems to require destroying the dev database, **stop and ask**.

> If you cannot run a test without touching the developer's data, you have not
> finished the task — you have found the bug this file exists to prevent.