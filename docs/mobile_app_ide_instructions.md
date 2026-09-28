# Mobile App — IDE Agent Master Instructions

> **This is the authoritative build specification for the NomNomytics Flutter
> companion app.** An IDE agent (Cursor / Copilot / Claude Code / any autonomous
> coding agent) should be able to scaffold, build and ship the entire app from
> this document **without re-deriving any decision**.
>
> **Read this file top to bottom before writing code.** Every section is
> normative. Where a rule says *must*, treat a violation as a build failure.
>
> **Companion documents**
> - [`API.md`](API.md) — the wire contract (endpoints, envelopes, error codes).
> - [`SOFTWARE_ARCHITECTURE.md`](SOFTWARE_ARCHITECTURE.md) — the Laravel backend.
> - [`FLUTTER_MOBILE_APP.md`](FLUTTER_MOBILE_APP.md) — the design-rationale
>   sibling of this file (why each choice was made; this file is *how to build it*).

---

## 0. Ground rules for the agent

1. **The backend is the source of truth.** Never invent an endpoint, a field or an
   enum value. If it is not in [`API.md`](API.md), it does not exist.
2. **Never hard-code a noun or a currency.** `Student` / `Employee` / `Boarder` /
   `Member` and every money format come from `GET /api/meta` at runtime.
3. **The Software Super Admin is not a mobile user.** Block them at every layer
   (§2). A single unguarded route is a cross-tenant data leak.
4. **Dispose everything.** Every BLoC, `TextEditingController`,
   `StreamSubscription`, `AnimationController` and `Timer` is disposed (§7.1).
   This is not a style preference; it is a memory-leak requirement.
5. **Offline is the default state, not an error.** Design every read/write for a
   flaky connection first, then let connectivity improve it (§5).
6. **One BLoC per screen.** No god-bloc, no shared mutable singleton state.

---

## 1. Tech stack & architecture

### 1.1 Stack

| Concern | Choice | Why this and not the alternative |
|---|---|---|
| Framework | **Flutter 3.19+ / Dart 3.3+** | One codebase, iOS + Android, first-class animation. |
| State | **BLoC** (`flutter_bloc` 8.x) | Explicit event→state transitions, testable without a widget tree. A `ChangeNotifier` collapses "queued" vs "saved" into one mutable object, which is the single most important distinction in an offline finance app (§5.3). |
| DI | **get_it** 8.x + **injectable** | Compile-time-safe service location with codegen. No `Provider` tree plumbing for non-widget services. |
| Routing | **go_router** 14.x | Declarative, deep-linkable, and guards via `redirect` — which is how auth and RBAC are enforced at the navigation layer (§2.3). |
| HTTP | **Dio** 5.x | Interceptors, cancellation tokens, timeouts. |
| Models | **freezed** + **json_serializable** | Immutable value objects + exhaustive `switch` on unions. |
| Local DB | **Isar** 3.1 | Indexed, typed, fast; handles the offline window well. *(Hive is an acceptable substitute — see §5.2 — but this spec assumes Isar.)* |
| Secure store | **flutter_secure_storage** 9.x | Keychain / EncryptedSharedPreferences for the bearer token ONLY. |
| Connectivity | **connectivity_plus** 6.x | Drives the sync trigger. |
| Background work | **workmanager** 0.5+ | Flushes the outbox when the app is not in the foreground (§5.3). |
| Push | **firebase_messaging** 15.x | FCM. |
| Crash | **firebase_crashlytics** 4.x | Field diagnostics. |
| Charts | **fl_chart** 0.69 | Analytics parity with the web dashboard. |
| Lint | **flutter_lints** 4.x + `analysis_options.yaml` | Enforced in CI. |

### 1.2 Clean Architecture — three layers, dependencies point inward

```
+----------------------------------------------------------+
|  PRESENTATION   widgets . pages . BLoC . (Flutter)       |
|      |  may import domain only                           |
|      v                                                   |
|  DOMAIN         entities . repository interfaces .       |
|                 use cases . (pure Dart, NO Flutter/Dio)  |
|      ^  implemented by                                  |
|      |                                                   |
|  DATA           models . datasources . repository impls  |
|                 (Dio, Isar, secure storage)              |
+----------------------------------------------------------+
```

| Layer | May depend on | **Must NOT** |
|---|---|---|
| **presentation** | domain | import `Dio`, `Isar`, or any concrete `data/` type |
| **domain** | nothing (pure Dart) | import Flutter, Dio, Isar, `freezed` |
| **data** | domain, core | contain business rules |

> **Enforcement:** the repository **interface** lives in `domain/`; its
> **implementation** lives in `data/`. A BLoC depends on the interface, so
> swapping the backend never touches the UI.

### 1.3 Why BLoC (the one-paragraph justification)

A finance client has a small number of **long-lived, stateful screens** — the
day's meal grid, the member roster, the balance summary — where the *sequence* of
transitions carries meaning:

```
MealsDayLoadRequested -> MealsDayLoading -> MealsDayLoaded(stale)
                      -> MealsDaySynced             (server truth)
                      -> MealsDaySaveRequested -> MealsDaySaving
                      -> MealsDaySaveSuccess        (confirmed)
                         \_ on failure -> MealsDaySaveQueued   <- the offline path
```

`MealsDaySaveQueued` is the state the whole offline design exists to represent. A
`ChangeNotifier` cannot express "saved" and "queued" as different states without
becoming a mutable bag of booleans.

**Conventions**
- One BLoC per screen. `BlocProvider` at the route; `BlocBuilder` to render;
  `BlocListener` for navigation/snackbars — **never** for rendering.
- Events are past-tense facts (`MealsDaySaved`); states are adjectives
  (`MealsDayLoaded`). Use `freezed` unions so `switch` is exhaustive.
- Repositories **never throw** into the presentation layer. They return
  `Either<Failure, T>` (`dartz`). The UI only ever sees a `Failure`.

---

## 2. Role-Based Access Control (RBAC)

The mobile app serves **exactly three roles**. Their screens adapt dynamically —
there is no "mobile app" and "admin mobile app"; there is one app that renders
what the signed-in role is permitted to see, mirroring the web matrix exactly.

| Role | Mobile access | Scope |
|---|---|---|
| **Institution Admin** | Full | Everything operational inside **their own** institution: members, meals, deposits, expenses, subsidies, vendors, departments, reports, claims review, payment verification, menus, user management, announcements, settings. |
| **Meal Manager** | Full operational | Records meals/deposits/expenses, manages members & vendors, reviews claims and payments, sees reports. **Cannot** manage the institution, broadcast announcements, or manage roles/users beyond viewing. |
| **Member** | Personal | Own balance, meals, deposits, analytics, payments, votes, claims. **No** administrative surface at all. |
| **Software Super Admin** | **BLOCKED — web only** | Global, cross-tenant operations. No tenant scope, so it would see every institution's data. Refused at every layer (§2.3). |

### 2.1 The exact permission matrix (from `RolesAndPermissionsSeeder`)

This is the **authoritative** mapping. The API enforces these at the *route*
level, so a `403` is the expected response when a role lacks a permission.

| Capability | Institution Admin | Meal Manager | Member |
|---|:--:|:--:|:--:|
| Own dashboard / meals / deposits / analytics | yes | yes | yes |
| Staff roster (`students.view`) | yes | yes | no |
| Create/edit/delete members (`students.manage`) | yes | yes | no |
| Record meals (`meals.entry`) | yes | yes | no |
| Record deposits / refunds (`meals.deposit`) | yes | yes | no |
| Record expenses (`meals.expense`) | yes | yes | no |
| Vendors (`vendors.view` / `.manage`) | yes | yes | no |
| Departments (`departments.view` / `.manage`) | yes | yes | no |
| Subsidies — view | yes | yes | no |
| Subsidies — manage | yes | no | no |
| Reports & forecast (`meals.reports`) | yes | yes | no |
| Claims — raise own | yes | yes | yes |
| Claims — **review** (`claims.review`) | yes | yes | no |
| Payment verification (`meals.deposit`) | yes | yes | no |
| Submit a payment (own) | yes | yes | yes |
| Menus — read what is open for voting | yes | yes | yes |
| Menus — create / move lifecycle (`meals.reports`) | yes | yes | no |
| Vote on a menu (own) | yes | yes | yes |
| Notifications — own | yes | yes | yes |
| Broadcast an announcement (`notifications.announce`) | yes | no | no |
| Institution settings — **view** | yes | yes | no |
| Institution settings — **manage** | yes | no | no |
| Theme (own) | yes | yes | yes |
| Change own password | yes | yes | yes |

> **Rule for the agent:** drive every UI affordance from the `permissions` array
> returned by `/api/auth/me` — **not** from the role name. The web does exactly
> this, and it is what keeps the two clients in step when a permission is
> re-scoped. Use role names only for layout decisions ("show the staff tab bar").

### 2.2 How the app adapts per role

```
Member            -> bottom nav: [ Summary . Meals . Deposits . More ]
                     "More": Analytics, Make a Payment, Meal Voting,
                             My Claims, Notifications, Profile, Theme

Meal Manager      -> bottom nav: [ Dashboard . Meals . Members . More ]
                     "More": Deposits, Expenses, Vendors, Departments,
                             Claims Review, Payment Verification,
                             Menus, Reports, Notifications, Profile

Institution Admin -> same as Meal Manager, PLUS
                     "More": Announcements, Institution Settings,
                             User Management, Subsidy Sources, Subscription
```

Implement this as **one `NavItem` list filtered by `permissions`** (mirroring
`resources/js/Utils/navItems.js` on the web) — never as three separate hard-coded
navigators. A single filtered list is what guarantees the app and the web cannot
drift.

### 2.3 The SSA block — three defence layers

The SSA must never hold a mobile session. Implement **all three**:

1. **API guard (authoritative).** `POST /api/auth/login` returns `403`
   `{"message":"Platform administrators must use the web console."}` and mints
   **no** token.
2. **Token guard.** `GET /api/auth/me` returns the same `403` if a stored token
   resolves to an SSA — so a token issued before this rule cannot be reused.
3. **Middleware guard.** The backend applies `mobile.not-ssa` to the entire
   authenticated API group, so every protected route refuses an SSA token.

**Client obligation:** the login screen surfaces the `403` message verbatim,
clears any stored token, and never persists one. Do **not** treat it as a
transient error and do not retry.

```dart
// features/auth/data/repositories/auth_repository_impl.dart
Future<Either<Failure, User>> login(String email, String password) async {
  try {
    final res = await _dio.post('/auth/login', data: {
      'email': email, 'password': password, 'device_name': await _deviceName(),
    });
    final token = res.data['data']['token'] as String;
    await _secure.writeToken(token);
    return Right(UserModel.fromJson(res.data['data']['user']));
  } on DioException catch (e) {
    // ErrorInterceptor already mapped this to a Failure. A 403 here means
    // "this account may not use the mobile app" and is TERMINAL — we do not
    // persist anything and we do not retry.
    return Left(e.error as Failure);
  }
}
```

> **SSA is also blocked at the router.** If `/auth/me` ever resolves to an SSA
> (a stale token, a restored backup), the `AuthGuard` redirect clears the session
> and returns to `/login` (§4.2).

---

## 3. Folder structure & file tree

```
nomnomytics_mobile/
├── android/ · ios/                        # platform shells
├── assets/
│   ├── fonts/Inter-{Regular,Medium,SemiBold,Bold}.ttf
│   └── images/
├── lib/
│   ├── main.dart                          # entry: assertSecure -> bootstrap -> runApp
│   ├── app.dart                           # MaterialApp.router + theme + locale
│   ├── bootstrap.dart                     # DI init, Crashlytics, Isar open, FCM
│   ├── injection.dart                     # get_it registrations (@injectable)
│   ├── injection.config.dart              # GENERATED — build_runner
│   │
│   ├── core/
│   │   ├── config/
│   │   │   ├── env.dart                   # API base URL per flavour + release HTTPS assert
│   │   │   └── constants.dart
│   │   ├── error/
│   │   │   ├── failures.dart              # sealed Failure hierarchy (§7.2)
│   │   │   └── exceptions.dart
│   │   ├── network/
│   │   │   ├── dio_client.dart            # Dio factory + interceptor wiring
│   │   │   ├── auth_interceptor.dart      # bearer token; 401 => clear + logout
│   │   │   ├── institution_interceptor.dart
│   │   │   └── error_interceptor.dart     # HTTP => Failure mapping (LAST)
│   │   ├── storage/
│   │   │   ├── secure_store.dart          # token ONLY
│   │   │   └── isar_service.dart          # Isar handle + collections + pruning
│   │   ├── sync/
│   │   │   ├── sync_service.dart          # single-flight run(): flush -> refresh -> reconcile
│   │   │   ├── sync_queue.dart            # the outbox
│   │   │   └── sync_scheduler.dart        # connectivity + lifecycle + workmanager
│   │   ├── notifications/
│   │   │   └── push_service.dart          # FCM token registration + routing
│   │   ├── theme/
│   │   │   ├── app_theme.dart             # AppTheme.fromMeta(meta)
│   │   │   ├── color_tokens.dart
│   │   │   └── typography.dart
│   │   └── utils/
│   │       ├── money_formatter.dart       # uses /api/meta currency config
│   │       ├── date_utils.dart
│   │       └── logger.dart                # redacts secrets
│   │
│   ├── features/
│   │   ├── auth/
│   │   │   ├── data/
│   │   │   │   ├── datasources/auth_remote_datasource.dart
│   │   │   │   ├── models/user_model.dart
│   │   │   │   └── repositories/auth_repository_impl.dart
│   │   │   ├── domain/
│   │   │   │   ├── entities/user.dart
│   │   │   │   ├── repositories/auth_repository.dart
│   │   │   │   └── usecases/{login,logout,restore_session}.dart
│   │   │   └── presentation/
│   │   │       ├── bloc/{auth_bloc,auth_event,auth_state}.dart
│   │   │       └── pages/{splash_page,login_page}.dart
│   │   │
│   │   ├── dashboard/                     # staff month summary
│   │   ├── member_home/                   # member's personal summary
│   │   ├── members/                       # roster + detail + CRUD
│   │   ├── meals/                         # day grid (THE offline write case)
│   │   ├── deposits/
│   │   ├── expenses/
│   │   ├── subsidies/
│   │   ├── vendors/
│   │   ├── departments/
│   │   ├── reports/                       # analytics + forecast (online only)
│   │   ├── menus/                         # voting  <- worked example, §8
│   │   ├── claims/                        # mine + review
│   │   ├── payments/                      # make a payment + verification
│   │   ├── notifications/
│   │   └── settings/                      # profile, theme, institution, password
│   │
│   └── shared/
│       ├── widgets/                       # AppButton, MoneyText, StatusChip,
│       │                                  # EmptyState, ErrorView, AppTextField
│       └── nav/
│           └── nav_items.dart             # ONE filtered nav list (§2.2)
├── test/
│   ├── unit/ · bloc/ · widget/ · integration/
└── pubspec.yaml
```

**Naming rules**
- One public class per file; the file name is the `snake_case` of the class.
- Feature-first, then layer: `features/<name>/{data,domain,presentation}`.
- Shared widgets live in `shared/widgets/` — never in a feature.
- Generated files (`*.freezed.dart`, `*.g.dart`, `injection.config.dart`) are
  committed so a fresh clone builds without `build_runner`.

---

## 4. Networking — Dio

### 4.1 Client construction

```dart
// core/network/dio_client.dart
@singleton
class DioClient {
  DioClient(this._store, this._onUnauthorised) {
    _dio = Dio(BaseOptions(
      baseUrl: Env.apiBaseUrl,                  // e.g. https://host/api
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 20),
      sendTimeout:    const Duration(seconds: 20),
      headers: const {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ))..interceptors.addAll([
      AuthInterceptor(_store, _onUnauthorised),   // 1. token
      const InstitutionInterceptor(),             // 2. diagnostic headers
      ErrorInterceptor(),                         // 3. LAST: maps to Failure
    ]);
  }

  final SecureStore _store;
  final void Function() _onUnauthorised;
  late final Dio _dio;
  Dio get dio => _dio;
}
```

> **Interceptor order is load-bearing.** Auth must run first so nothing
> short-circuits before the token is attached; the error mapper must run **last**
> so it observes the final response after every other interceptor.

### 4.2 Token handling (bearer token injection)

The backend issues **Sanctum personal access tokens with a 30-day lifetime**
([API.md §4](API.md)). There is no refresh endpoint — so treat a `401` as
**terminal**:

```dart
class AuthInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions o, RequestInterceptorHandler h) async {
    final isPublic = o.path.startsWith('/auth/login') ||
                     o.path.startsWith('/auth/register') ||
                     o.path.startsWith('/meta');
    if (!isPublic) {
      final t = await _store.readToken();
      if (t != null && t.isNotEmpty) o.headers['Authorization'] = 'Bearer $t';
    }
    h.next(o);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler h) async {
    if (err.response?.statusCode == 401) {
      await _store.clear();
      _onUnauthorised();          // router redirects to /login
    }
    // 403 is NOT 401: authenticated but refused (inactive account, or an SSA).
    // Surface the server message; DO NOT sign the user out.
    h.next(err);
  }
}
```

> **"Auto-refresh" in this codebase means re-authentication, not a token swap.**
> There is no refresh-token endpoint. On `401`, clear the token and return to the
> login screen. Implementation agents must not invent a `/auth/refresh` call.

### 4.3 Global error interceptor -> one failure vocabulary

Map **every** transport outcome to exactly one `Failure` (§7.2), so the UI has a
single switch to render:

| Condition | Failure | UX |
|---|---|---|
| `connectionTimeout` / `receiveTimeout` / `sendTimeout` | `NetworkFailure` | "Slow connection — retry." |
| `connectionError` | `NetworkFailure` | Offline banner; **queue the write**. |
| **422** | `ValidationFailure(errors)` | Inline `errors.<field>[0]` under the field. |
| **401** | `UnauthenticatedFailure` | Clear token -> `/login`. |
| **403** | `ForbiddenFailure(message)` | Show `message` verbatim (inactive / SSA). |
| **404** | `NotFoundFailure` | "This record no longer exists." Pop. |
| **409** | `ConflictFailure(message)` | Explain + offer the alternative (deactivate, not delete). |
| **429** | `RateLimitedFailure(retryAfter)` | Countdown from `Retry-After`; disable submit. |
| **5xx** | `ServerFailure` | "Something went wrong." Report to Crashlytics. |

```dart
class ErrorInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler h) {
    final r = err.response;
    final status = r?.statusCode ?? 0;
    final serverMsg = (r?.data is Map) ? r?.data['message'] as String? : null;

    final Failure failure = switch (err.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.receiveTimeout ||
      DioExceptionType.sendTimeout  => const NetworkFailure('The connection is slow. Please try again.'),
      DioExceptionType.connectionError => const NetworkFailure(),
      _ => switch (status) {
        401 => const UnauthenticatedFailure(),
        403 => ForbiddenFailure(serverMsg ?? 'You do not have permission to do that.'),
        404 => NotFoundFailure(serverMsg ?? 'This record no longer exists.'),
        409 => ConflictFailure(serverMsg ?? 'That action conflicts with the current state.'),
        422 => ValidationFailure(_errors(r?.data), serverMsg ?? 'Please check the highlighted fields.'),
        429 => RateLimitedFailure(_retryAfter(r)),
        _ when status >= 500 => const ServerFailure(),
        _ => ServerFailure(serverMsg ?? 'Something went wrong. Please try again.'),
      },
    };

    // Re-throw carrying the mapped Failure, so repositories can return Left(failure).
    h.reject(DioException(requestOptions: err.requestOptions, error: failure, response: r));
  }
}
```

---

## 5. Offline-first & auto-sync

### 5.1 What is cached, and for how long

**The rule: cache what the user is standing in front of; never cache history.**

| Data | Cached? | TTL | Notes |
|---|---|---|---|
| `/api/meta` | yes | 24 h | Terminology, currency, theme. Re-fetched on tenant change. |
| `/api/auth/me` | yes | session | Revalidated on every cold start. |
| `/api/dashboard` (current month) | yes | 15 min | The home screen. |
| `/api/members` (roster) | yes | 30 min | Needed to record meals offline. |
| `/api/meals/day` (today +/- 2 days) | yes | 24 h | **The critical offline case** — the day grid. |
| `/api/deposits` (current month) | yes | 15 min | |
| `/api/subsidies/sources` | yes | 7 d | Configuration; rarely changes. |
| **Historical months** (> 1 month old) | **NEVER** | — | Stale financial history is worse than none: a user could act on a superseded balance. Past months require connectivity. |
| Reports / analytics / forecast | no | — | Server-computed aggregates; must be fresh. |
| Menu vote tallies | no | — | Counts change constantly; stale tallies mislead voters. |
| Notifications list | yes | 5 min | Last page only. |

### 5.2 Isar collections

```dart
@collection
class CacheEntry {
  Id id = Isar.autoIncrement;
  @Index(unique: true, replace: true)
  late String key;          // "<institutionId>:<endpoint>:<params>"
  late String payloadJson;  // the RAW response envelope, verbatim
  late DateTime cachedAt;
  late DateTime expiresAt;  // pruning reads this
}

@collection
class PendingMutation {
  Id id = Isar.autoIncrement;
  late String endpoint;        // POST target, e.g. "/meals/day"
  late String method;          // POST | PATCH | PUT | DELETE
  late String bodyJson;
  @Index(unique: true, replace: true)
  late String idempotencyKey;  // client-generated UUID v4
  late DateTime queuedAt;
  late int attemptCount;
  String? lastError;
  late String status;          // 'pending' | 'failed'
}
```

`payloadJson` stores the **raw response envelope**, not a re-serialised model.
This is deliberate: a new field the backend adds flows through to the UI after an
app update with **no cache-schema migration**.

**Pruning.** An `IsarService.prune()` runs on app start and removes any
`CacheEntry` past `expiresAt`, plus every entry whose `key` is not for the
current month. This is what enforces "never cache history" — and it keeps the
local database small enough that correct is cheap.

*(If Hive is substituted: use one `Box<String>` for the JSON cache keyed the same
way and one `Box<String>` for the outbox. The design is storage-agnostic; only
the adapter changes.)*

### 5.3 The sync engine

```
connectivity_plus stream ---+
app lifecycle (resumed)  ---+--> SyncScheduler --> SyncService.run()
manual pull-to-refresh   ---+
workmanager (background) ---+
```

`SyncService.run()` is **serialised** (a single-flight mutex — two concurrent
runs would double-post the outbox) and strictly ordered:

1. **Flush the outbox FIRST.** Pending mutations are the user's *intent*; they
   must reach the server before any read, or a refresh could overwrite
   optimistic state with stale server data.
2. **Re-read the invalidated caches.** Only the keys the flushed mutations touch,
   plus the home screen.
3. **Reconcile.** Server truth replaces the cache. Any optimistic row whose server
   counterpart is missing is **surfaced** — never silently dropped.

```dart
@singleton
class SyncService {
  SyncService(this._queue, this._dio, this._cache);
  Completer<void>? _inFlight;

  Future<void> run() async {
    // SINGLE-FLIGHT: a second caller awaits the first run instead of racing it.
    if (_inFlight != null) return _inFlight!.future;

    final c = Completer<void>();
    _inFlight = c;
    try {
      await _flushOutbox();
      await _refreshInvalidated();
      await _reconcile();
      c.complete();
    } catch (e, s) {
      c.completeError(e, s);
    } finally {
      _inFlight = null;
    }
  }
}
```

#### Idempotency (mandatory)

Every queued write carries a client-generated `idempotencyKey`. A retry after a
dropped response must not double-post a deposit. Implement it as a header:

```dart
Future<void> _flushOutbox() async {
  for (final m in await _queue.pending()) {
    try {
      await _dio.request(m.endpoint,
        data: jsonDecode(m.bodyJson),
        options: Options(
          method: m.method,
          headers: {'Idempotency-Key': m.idempotencyKey},
        ),
      );
      await _queue.remove(m.id);              // confirmed
    } on DioException catch (e) {
      switch (e.error) {
        case ValidationFailure():
          await _queue.markFailed(m.id, e.error.toString());  // KEEP the item
        case NotFoundFailure():
          await _queue.remove(m.id);                          // record is gone
        case ConflictFailure():
          await _queue.markFailed(m.id, 'conflict');          // let the user choose
        default:
          await _queue.bumpAttempt(m.id);                     // retry w/ backoff
      }
    }
  }
}
```

#### Conflict policy — last-write-wins per record, with a visible report

| Server response | Action |
|---|---|
| **422** validation | Mark the queue item **failed**, **keep it**, show it in a "needs attention" list. **Never discard user input.** |
| **404** (record deleted) | Drop the item; tell the user which one and why. |
| **409** conflict | Keep the item; surface both versions; let the user choose. |
| **5xx / network** | Retry with exponential backoff: 1s, 2s, 4s, 8s, 30s (capped). |

**Backoff cap.** After **6 attempts** an item parks as `failed` and is surfaced in
the UI. An unbounded retry loop drains the battery and hides a real problem.

### 5.4 Offline UX contract

- A **persistent banner** appears the moment connectivity drops, stating what
  still works: *"Offline — you can still record meals and deposits. Changes sync
  automatically."*
- Every screen reading uncached data shows a **stale-data chip** with the cache
  timestamp — not a spinner forever.
- Every queued write shows a **pending badge** on the row it affects. The user
  must never wonder whether their entry was saved.
- Writes that cannot be queued (reports, votes) are **disabled with a reason** —
  not silently failed.

---

## 6. Push notifications — FCM

### 6.1 Registration

On successful login, and on every token refresh:

```
POST /api/auth/devices   { "device_name": "Pixel 8 (Android 14)" }
```

This mints an additional Sanctum token **and** is the hook where the FCM
registration token is attached. Re-register whenever `onTokenRefresh` fires.

```dart
FirebaseMessaging.instance.onTokenRefresh.listen((fcmToken) async {
  await _api.registerDevice(deviceName: await _deviceName());
});
```

### 6.2 Notification categories

| Category | Trigger | Deep link | Priority |
|---|---|---|---|
| `deposit_verified` | A manager approves the member's payment | `/deposits/{id}` | high |
| `payment_rejected` | A manager rejects it, with a reason | `/payments/{id}` | high |
| `menu_vote_open` | A new menu opens for voting | `/menus/{id}` | normal |
| `menu_approved` | The winning menu is approved | `/menus/{id}` | normal |
| `claim_updated` | A claim's status changes | `/claims/{id}` | normal |
| `balance_low` | Balance falls below the threshold | `/dashboard` | high |
| `dues_reminder` | Scheduled, end of month | `/dashboard` | normal |
| `announcement` | Institution broadcast | `/notifications` | normal |

### 6.3 Handling — the four cases that must all work

- **Foreground:** show an in-app banner (the OS suppresses the tray notification)
  and refresh the affected screen.
- **Background tap:** route via `data.deep_link` through `go_router`.
- **Cold start from a notification:** buffer the payload in `bootstrap.dart` and
  replay it **once the router and session are ready**. This is the classic bug —
  the tap that launches the app must not be swallowed.
- **Tenant safety:** drop any notification whose `institution_id` does not match
  the session's. A device that switched accounts must never show another
  institution's alert.

**Permission:** request after the **first successful login** with a soft prompt
("Get notified when your deposit is verified") — never on first launch.

---

## 7. Performance, error handling & memory-leak prevention

### 7.1 Disposal — the non-negotiable rule

> **Every** BLoC, `TextEditingController`, `FocusNode`, `ScrollController`,
> `AnimationController`, `StreamSubscription`, `Timer` and `PageController`
> **MUST** be disposed. A missed disposal is a memory leak — the object is rooted
> by its listener and can never be collected.

**Consequence of failure:** memory grows with every visit to the screen;
navigation becomes sluggish; on low-end Android the OS eventually kills the app.
It will not fail loudly in development — it degrades in production, on the
users' devices.

```dart
class MealsDayPage extends StatefulWidget {
  const MealsDayPage({super.key});
  @override
  State<MealsDayPage> createState() => _MealsDayPageState();
}

class _MealsDayPageState extends State<MealsDayPage> {
  late final MealsDayBloc _bloc;
  final _searchController = TextEditingController();
  final _scrollController = ScrollController();
  StreamSubscription<ConnectivityResult>? _connectivitySub;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _bloc = sl<MealsDayBloc>()..add(const MealsDayLoadRequested());
    _connectivitySub = Connectivity().onConnectivityChanged.listen((_) {
      _bloc.add(const MealsDaySyncRequested());
    });
  }

  @override
  void dispose() {
    // ORDER MATTERS: listeners first, then the objects they point at.
    _debounce?.cancel();
    _connectivitySub?.cancel();
    _searchController.dispose();
    _scrollController.dispose();
    _bloc.close();                       // BLoC must be closed, not just dropped
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => BlocProvider.value(
        value: _bloc,
        child: const _MealsDayView(),
      );
}
```

**Checklist for the agent — apply to every `StatefulWidget`:**

- [ ] Every controller created in `initState` is disposed in `dispose`.
- [ ] Every `StreamSubscription` is `cancel()`ed in `dispose`.
- [ ] Every `Timer` is `cancel()`ed in `dispose`.
- [ ] Every BLoC created locally is `close()`d in `dispose`.
- [ ] `BlocProvider.value` (not `create`) is used when the BLoC was made in
      `initState`, so the provider does **not** also try to close it.
- [ ] No `setState` is called after an `await` without an `if (!mounted) return;`
      guard.

```dart
Future<void> _save() async {
  await _repo.save();
  if (!mounted) return;      // the widget may be gone — never setState blindly
  setState(() => _saving = false);
}
```

**BLoC internals:** declare `StreamSubscription`s as fields and cancel them in
`close()`:

```dart
@override
Future<void> close() {
  _connectivitySub?.cancel();
  _debounce?.cancel();
  return super.close();
}
```

### 7.2 The Failure hierarchy (sealed)

```dart
sealed class Failure extends Equatable {
  const Failure(this.message);
  final String message;
  @override
  List<Object?> get props => [message];
}

class NetworkFailure         extends Failure { const NetworkFailure([super.message = 'No connection. Your changes will sync when you are back online.']); }
class UnauthenticatedFailure extends Failure { const UnauthenticatedFailure([super.message = 'Your session has expired. Please sign in again.']); }
class ForbiddenFailure       extends Failure { const ForbiddenFailure([super.message = 'You do not have permission to do that.']); }
class NotFoundFailure        extends Failure { const NotFoundFailure([super.message = 'This record no longer exists.']); }
class ConflictFailure        extends Failure { const ConflictFailure([super.message = 'That action conflicts with the current state.']); }
class ServerFailure          extends Failure { const ServerFailure([super.message = 'Something went wrong. Please try again.']); }
class CacheFailure           extends Failure { const CacheFailure([super.message = 'Could not read the local data.']); }

class ValidationFailure extends Failure {
  const ValidationFailure(this.errors, [super.message = 'Please check the highlighted fields.']);
  final Map<String, List<String>> errors;
  String? firstError(String field) => errors[field]?.firstOrNull;
  @override List<Object?> get props => [message, errors];
}

class RateLimitedFailure extends Failure {
  const RateLimitedFailure([this.retryAfter = const Duration(seconds: 60)])
      : super('Too many attempts. Please wait a moment and try again.');
  final Duration retryAfter;
  @override List<Object?> get props => [message, retryAfter];
}
```

`sealed` matters: it forces an **exhaustive** `switch` at every handling site, so
adding a failure type later becomes a compile error everywhere it must be
handled — rather than a silent fall-through to a generic branch.

### 7.3 Global error surface

One `ErrorView` widget renders every `Failure`; one `AppSnackBar` shows transient
ones. Screens never write their own error text for a transport problem.

```dart
Widget showFailure(BuildContext ctx, Failure f) => switch (f) {
  NetworkFailure()         => const OfflineBanner(),
  UnauthenticatedFailure() => const _RedirectToLogin(),
  ForbiddenFailure(:final message) => AppSnackBar.error(message),
  ValidationFailure(:final errors) => _InlineFieldErrors(errors),
  NotFoundFailure(:final message)  => AppSnackBar.warning(message),
  ConflictFailure(:final message)  => AppSnackBar.warning(message),
  RateLimitedFailure(:final retryAfter) =>
      AppSnackBar.warning('Too many attempts. Retry in ${retryAfter.inSeconds}s.'),
  ServerFailure()          => const ErrorView(title: 'Something went wrong'),
  CacheFailure(:final message) => AppSnackBar.error(message),
};
```

A `BlocObserver` wraps `onError` globally, logs with a redacted logger and
reports to Crashlytics — so an uncaught BLoC error is never invisible:

```dart
class AppBlocObserver extends BlocObserver {
  @override
  void onError(BlocBase bloc, Object error, StackTrace st) {
    Logger.e('BLoC error in ${bloc.runtimeType}', error, st);
    FirebaseCrashlytics.instance.recordError(error, st, fatal: false);
    super.onError(bloc, error, st);
  }
}
// bootstrap.dart:  Bloc.observer = AppBlocObserver();
```

### 7.4 Performance rules

- `const` constructors wherever possible (the linter enforces this).
- `ListView.builder` / `SliverList` for any list over ~20 rows — never
  `Column(children: list.map(...))`.
- `RepaintBoundary` around animated or heavy subtrees.
- **Never** do work in `build()`. Derive in the BLoC; narrow rebuilds with
  `BlocSelector` / `buildWhen`.
- Images: `cached_network_image` + explicit `cacheWidth`/`cacheHeight`.
- Target: **16 ms** frame budget (60 fps); profile with DevTools on a mid-range
  Android device, not a simulator.

---

## 8. Worked example — the "Meal Voting" feature

Replicate this exact pattern for every feature. It is deliberately complete:
entity -> model -> datasource -> repository -> use case -> BLoC -> UI, with
disposal and error handling in place.

### 8.1 Domain — entity (pure Dart)

```dart
// features/menus/domain/entities/menu.dart
class MenuOption extends Equatable {
  const MenuOption({
    required this.id,
    required this.name,
    required this.votes,
    required this.percentage,
    required this.isMyChoice,
  });

  final int id;
  final String name;
  final int votes;
  final double percentage;
  final bool isMyChoice;

  @override
  List<Object?> get props => [id, name, votes, percentage, isMyChoice];
}

class Menu extends Equatable {
  const Menu({
    required this.id,
    required this.title,
    required this.mealTypeLabel,
    required this.status,
    required this.statusLabel,
    required this.isOpenForVoting,
    required this.hasVoted,
    required this.totalVotes,
    required this.options,
  });

  final int id;
  final String title;
  final String mealTypeLabel;
  final String status;
  final String statusLabel;

  /// Server-derived: status AND the voting window. Never recomputed locally.
  final bool isOpenForVoting;
  final bool hasVoted;
  final int totalVotes;
  final List<MenuOption> options;

  @override
  List<Object?> get props => [id, title, status, isOpenForVoting, hasVoted, options];
}
```

### 8.2 Data — model (`freezed` + json_serializable)

```dart
// features/menus/data/models/menu_model.dart
part 'menu_model.freezed.dart';
part 'menu_model.g.dart';

@freezed
class MenuModel with _$MenuModel {
  const factory MenuModel({
    required int id,
    required String title,
    @JsonKey(name: 'meal_type_label') required String mealTypeLabel,
    required String status,
    @JsonKey(name: 'status_label') required String statusLabel,
    @JsonKey(name: 'is_open_for_voting') required bool isOpenForVoting,
    @JsonKey(name: 'has_voted') required bool hasVoted,
    @JsonKey(name: 'total_votes') required int totalVotes,
    required List<MenuOptionModel> options,
  }) = _MenuModel;

  factory MenuModel.fromJson(Map<String, dynamic> json) => _$MenuModelFromJson(json);
}

@freezed
class MenuOptionModel with _$MenuOptionModel {
  const factory MenuOptionModel({
    required int id,
    required String name,
    required int votes,
    required double percentage,
    @JsonKey(name: 'is_my_choice') required bool isMyChoice,
  }) = _MenuOptionModel;

  factory MenuOptionModel.fromJson(Map<String, dynamic> json) => _$MenuOptionModelFromJson(json);
}

// ---- Mapper: model -> entity (data layer owns the conversion) ----
extension MenuModelX on MenuModel {
  Menu toEntity() => Menu(
        id: id,
        title: title,
        mealTypeLabel: mealTypeLabel,
        status: status,
        statusLabel: statusLabel,
        isOpenForVoting: isOpenForVoting,
        hasVoted: hasVoted,
        totalVotes: totalVotes,
        options: options
            .map((o) => MenuOption(
                  id: o.id, name: o.name, votes: o.votes,
                  percentage: o.percentage, isMyChoice: o.isMyChoice,
                ))
            .toList(),
      );
}
```

> **Field names are snake_case on the wire** — every `@JsonKey` must match
> [`API.md`](API.md) exactly. The API returns `is_my_choice`, `has_voted`,
> `total_votes`; the Dart side is camelCase.

### 8.3 Data — datasource + repository

```dart
// features/menus/data/datasources/menu_remote_datasource.dart
@injectable
class MenuRemoteDataSource {
  MenuRemoteDataSource(this._dio);
  final Dio _dio;

  Future<List<MenuModel>> fetchMenus() async {
    final res = await _dio.get('/menus');
    return (res.data['data'] as List)
        .map((j) => MenuModel.fromJson(j as Map<String, dynamic>))
        .toList();
  }

  Future<MenuModel> vote(int menuId, int optionId) async {
    final res = await _dio.post('/menus/$menuId/vote', data: {'option_id': optionId});
    return MenuModel.fromJson(res.data['data'] as Map<String, dynamic>);
  }
}

// features/menus/domain/repositories/menu_repository.dart   (INTERFACE — domain)
abstract class MenuRepository {
  Future<Either<Failure, List<Menu>>> getMenus();
  Future<Either<Failure, Menu>> vote({required int menuId, required int optionId});
}

// features/menus/data/repositories/menu_repository_impl.dart  (IMPL — data)
@Injectable(as: MenuRepository)
class MenuRepositoryImpl implements MenuRepository {
  MenuRepositoryImpl(this._remote);
  final MenuRemoteDataSource _remote;

  @override
  Future<Either<Failure, List<Menu>>> getMenus() async {
    try {
      final models = await _remote.fetchMenus();
      return Right(models.map((m) => m.toEntity()).toList());
    } on DioException catch (e) {
      // ErrorInterceptor guarantees `e.error` is a Failure.
      return Left(e.error is Failure ? e.error as Failure : const ServerFailure());
    }
  }

  @override
  Future<Either<Failure, Menu>> vote({required int menuId, required int optionId}) async {
    try {
      return Right((await _remote.vote(menuId, optionId)).toEntity());
    } on DioException catch (e) {
      return Left(e.error is Failure ? e.error as Failure : const ServerFailure());
    }
  }
}
```

> **Voting is deliberately NOT queued offline.** Tallies change constantly, so a
> queued vote would be cast against a menu that may have closed. The UI disables
> the button with the reason "Connect to vote" — see §5.4.

### 8.4 Presentation — BLoC (`freezed` states, exhaustive switch)

```dart
// features/menus/presentation/bloc/menu_event.dart
@freezed
class MenuEvent with _$MenuEvent {
  const factory MenuEvent.loadRequested() = MenuLoadRequested;
  const factory MenuEvent.voteSubmitted({required int menuId, required int optionId}) = MenuVoteSubmitted;
}

// features/menus/presentation/bloc/menu_state.dart
@freezed
class MenuState with _$MenuState {
  const factory MenuState.initial() = MenuInitial;
  const factory MenuState.loading() = MenuLoading;
  const factory MenuState.loaded(List<Menu> menus) = MenuLoaded;
  const factory MenuState.voting(List<Menu> menus, int menuId) = MenuVoting;
  const factory MenuState.failure(Failure failure) = MenuFailure;
}

// features/menus/presentation/bloc/menu_bloc.dart
@injectable
class MenuBloc extends Bloc<MenuEvent, MenuState> {
  MenuBloc(this._repo) : super(const MenuState.initial()) {
    on<MenuLoadRequested>(_onLoad);
    on<MenuVoteSubmitted>(_onVote);
  }

  final MenuRepository _repo;

  Future<void> _onLoad(MenuLoadRequested e, Emitter<MenuState> emit) async {
    emit(const MenuState.loading());
    final result = await _repo.getMenus();
    result.fold(
      (f) => emit(MenuState.failure(f)),
      (menus) => emit(MenuState.loaded(menus)),
    );
  }

  Future<void> _onVote(MenuVoteSubmitted e, Emitter<MenuState> emit) async {
    final current = state;
    final menus = current is MenuLoaded
        ? current.menus
        : (current is MenuVoting ? current.menus : <Menu>[]);

    emit(MenuState.voting(menus, e.menuId));   // lock the row, show a spinner

    final result = await _repo.vote(menuId: e.menuId, optionId: e.optionId);
    result.fold(
      (f) => emit(MenuState.failure(f)),
      // The server returns the refreshed tallies, so no second fetch is needed.
      (updated) => emit(MenuState.loaded([
        for (final m in menus) if (m.id == updated.id) updated else m,
      ])),
    );
  }
}
```

### 8.5 Presentation — page (with correct disposal)

```dart
// features/menus/presentation/pages/menu_page.dart
class MenuPage extends StatefulWidget {
  const MenuPage({super.key});
  @override
  State<MenuPage> createState() => _MenuPageState();
}

class _MenuPageState extends State<MenuPage> {
  late final MenuBloc _bloc;

  @override
  void initState() {
    super.initState();
    _bloc = sl<MenuBloc>()..add(const MenuEvent.loadRequested());
  }

  @override
  void dispose() {
    // LEAK PREVENTION: close the BLoC before the State is torn down.
    _bloc.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => BlocProvider.value(
        value: _bloc,
        child: const _MenuView(),
      );
}

class _MenuView extends StatelessWidget {
  const _MenuView();

  @override
  Widget build(BuildContext context) {
    // BlocBuilder renders; BlocListener shows transient messages. Never mix.
    return BlocListener<MenuBloc, MenuState>(
      listenWhen: (a, b) => b is MenuFailure,
      listener: (ctx, state) {
        if (state is MenuFailure) showFailure(ctx, state.failure);
      },
      child: BlocBuilder<MenuBloc, MenuState>(
        builder: (ctx, state) => switch (state) {
          MenuInitial()      => const SizedBox.shrink(),
          MenuLoading()      => const MenuSkeleton(),
          MenuFailure(:final failure) => ErrorView.fromFailure(failure),
          MenuLoaded(:final menus)    => _MenuList(menus: menus),
          MenuVoting(:final menus)    => _MenuList(menus: menus),
        },
      ),
    );
  }
}

class _MenuList extends StatelessWidget {
  const _MenuList({required this.menus});
  final List<Menu> menus;

  @override
  Widget build(BuildContext context) {
    if (menus.isEmpty) {
      return const EmptyState(
        icon: Icons.restaurant_menu,
        title: 'No menus open',
        body: 'Check back when the kitchen posts the next menu.',
      );
    }

    return ListView.builder(
      itemCount: menus.length,
      itemBuilder: (ctx, i) {
        final menu = menus[i];
        return Card(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ListTile(
                title: Text(menu.title),
                subtitle: Text('${menu.mealTypeLabel} · ${menu.totalVotes} votes'),
                trailing: StatusChip(label: menu.statusLabel, tone: _tone(menu.status)),
              ),
              ...[
                for (final o in menu.options)
                  RadioListTile<int>(
                    value: o.id,
                    groupValue: menu.options.firstWhereOrNull((x) => x.isMyChoice)?.id,
                    // Disabled with a REASON when voting is closed (§5.4).
                    onChanged: menu.isOpenForVoting
                        ? (id) => ctx.read<MenuBloc>().add(
                              MenuEvent.voteSubmitted(menuId: menu.id, optionId: id!),
                            )
                        : null,
                    title: Text(o.name),
                    subtitle: Text('${o.votes} votes · ${o.percentage}%'),
                  ),
              ],
              if (!menu.isOpenForVoting)
                const Padding(
                  padding: EdgeInsets.all(12),
                  child: Text('Voting is closed for this menu.',
                      style: TextStyle(fontSize: 12, color: ColorTokens.slate400)),
                ),
            ],
          ),
        );
      },
    );
  }

  static String _tone(String status) => switch (status) {
        'approved' => 'emerald',
        'voting'   => 'sky',
        'rejected' => 'rose',
        'cancelled'=> 'slate',
        _          => 'amber',
      };
}
```

### 8.6 Test — BLoC (`bloc_test` + `mocktail`)

```dart
class MockMenuRepository extends Mock implements MenuRepository {}

void main() {
  late MockMenuRepository repo;

  setUp(() => repo = MockMenuRepository());

  blocTest<MenuBloc, MenuState>(
    'emits [loading, loaded] on a successful load',
    build: () {
      when(() => repo.getMenus()).thenAnswer((_) async => const Right(<Menu>[]));
      return MenuBloc(repo);
    },
    act: (bloc) => bloc.add(const MenuEvent.loadRequested()),
    expect: () => [const MenuState.loading(), const MenuState.loaded(<Menu>[])],
  );

  blocTest<MenuBloc, MenuState>(
    'surfaces a NetworkFailure as MenuFailure',
    build: () {
      when(() => repo.getMenus())
          .thenAnswer((_) async => const Left(NetworkFailure()));
      return MenuBloc(repo);
    },
    act: (bloc) => bloc.add(const MenuEvent.loadRequested()),
    expect: () => [const MenuState.loading(), const MenuState.failure(NetworkFailure())],
  );
}
```

---

## 9. UI/UX & animation guidelines — matching the web

The app must feel like the same product. Tokens are read from `/api/meta` at
startup and mapped onto a `ThemeData`.

### 9.1 Colour tokens

The web sidebar/theme layer writes CSS variables from the institution's accent
(`Institution::THEMES`). The mobile equivalent:

```dart
class ColorTokens {
  static const accents = <String, Color>{
    'indigo':  Color(0xFF4F46E5),
    'emerald': Color(0xFF059669),
    'sky':     Color(0xFF0284C7),
    'violet':  Color(0xFF7C3AED),
    'rose':    Color(0xFFE11D48),
    'amber':   Color(0xFFD97706),
    'slate':   Color(0xFF334155),
    'teal':    Color(0xFF0D9488),
  };

  // Neutrals mirror Tailwind slate, the web's base ramp.
  static const slate50  = Color(0xFFF8FAFC);
  static const slate100 = Color(0xFFF1F5F9);
  static const slate200 = Color(0xFFE2E8F0);
  static const slate400 = Color(0xFF94A3B8);
  static const slate600 = Color(0xFF475569);
  static const slate900 = Color(0xFF0F172A);

  // Semantic tones for the status chips the web uses.
  static const success = Color(0xFF10B981);
  static const warning = Color(0xFFF59E0B);
  static const danger  = Color(0xFFF43F5E);
}
```

`AppTheme.fromMeta(meta)` builds `ColorScheme.fromSeed(accent)` and applies the
institution's `mode` (light/dark) and radius token. **Dark mode is mandatory** —
the web supports it and the app must match.

### 9.2 Typography, spacing, radius, density

| Token | Web (Tailwind) | Flutter |
|---|---|---|
| Base font | Inter | `Inter` (bundled asset) |
| Body | `text-sm` 14 px | 14 sp / height 1.45 |
| Label | `text-[11px] font-bold uppercase tracking-wider` | 11 sp, w700, letterSpacing 0.8 |
| Heading | `text-2xl font-bold` | 24 sp, w700, letterSpacing -0.2 |
| Radius — `lg` | `rounded-xl` 12 px | `BorderRadius.circular(12)` |
| Radius — `md` | `rounded-lg` 8 px | `BorderRadius.circular(8)` |
| Density — comfortable | 16 px padding | `EdgeInsets.all(16)` |
| Density — compact | 12 px padding | `EdgeInsets.all(12)` |
| Elevation | `shadow-sm` | `elevation: 1`, low-opacity shadow |

### 9.3 Component parity

| Web component | Flutter equivalent |
|---|---|
| `Sidebar` | `NavigationBar` (bottom, <=4 destinations) + `Drawer` for the full module list |
| `Sidebar` brand header | `DrawerHeader` — **same tenant-vs-platform logic** (§9.5) |
| Status chip (`tone`) | `StatusChip(tone:)` — `emerald/amber/rose/sky/slate` |
| `PrimaryButton` | `AppButton` (filled, accent, 12 radius, 48 min height) |
| `TextInput` / `PasswordInput` | `AppTextField` / `AppPasswordField` (with eye toggle) |
| `InputError` | `AppFieldError` — 12 sp, danger |
| `EmptyState` | `EmptyState` (icon + title + body + optional action) |
| Flash message | `AppSnackBar` (success/error variants) |
| Member roster card | `MemberTile` with balance + `is_due` badge |
| `TopBar` | `AppBar` (title + breadcrumb, actions right) |

### 9.4 Animation

Animations must be **fluid and non-distracting**, and must never block input.

- Durations: **200 ms** for state transitions, **300 ms** for page transitions,
  **150 ms** for micro-interactions. Nothing over 400 ms except explicit
  `Hero` / skeleton shimmer.
- Curves: `Curves.easeOutCubic` for entrances, `Curves.easeInOut` for toggles.
- Prefer **implicit** animations (`AnimatedSwitcher`, `AnimatedContainer`,
  `TweenAnimationBuilder`) over hand-rolled `AnimationController`s.
- When an `AnimationController` **is** needed, it **must** be disposed (§7.1).
- Page transitions via `go_router`'s `CustomTransitionPage` — a subtle fade +
  8 px lift, mirroring the web's `animate-page-in`.
- Respect reduced motion:

```dart
final reduceMotion = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
// Skip decorative motion entirely when the OS asks for it.
```

- **Never** animate during a list scroll or inside a `ListView.builder` item
  beyond a cheap implicit widget.

### 9.5 Branding hierarchy — the same rule as the web

The drawer header must apply the **identical** tenant-vs-platform logic as
`resources/js/Components/Sidebar.jsx`:

- **A tenant user** (Member / Meal Manager / Institution Admin): the institution's
  name, subtitle and logo — **falling back to the software logo** when the
  institution has not uploaded one.
- **An SSA**: never reaches the app (§2). Stated only so the component is written
  correctly from the start.

```dart
Widget buildBrandHeader(BuildContext ctx) {
  final meta = ctx.watch<MetaCubit>().state.meta;
  final platform = ctx.watch<MetaCubit>().state.platform;

  final name = meta?.institution?.name ?? platform.controlCenter;
  final subtitle = meta?.institution?.subtitle
      ?? meta?.institution?.typeLabel
      ?? 'Shared meals, tracked';
  final logoUrl = meta?.institution?.logoUrl;   // null => software logo

  return DrawerHeader(
    child: Row(children: [
      logoUrl != null
          ? Image.network(logoUrl, height: 32, width: 32)
          : const SoftwareLogoMark(),          // the shared fallback
      const SizedBox(width: 12),
      Expanded(child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(name,     style: AppTypography.brandTitle,    overflow: TextOverflow.ellipsis),
          Text(subtitle, style: AppTypography.brandSubtitle, overflow: TextOverflow.ellipsis),
        ],
      )),
    ]),
  );
}
```

### 9.6 Terminology — never hard-code a noun

The institution's `type` decides whether members are *Students*, *Employees*,
*Boarders* or *Members*. `/api/meta` returns a `terms` map; all copy reads it:

```dart
// correct
Text(ctx.t('members'))            // "Students" | "Employees" | "Boarders" | "Members"

// never
Text('Students')
```

Money is formatted **only** from `/api/meta`'s `currency` block — symbol,
position, separators, precision, abbreviation threshold. A hard-coded local
symbol is a bug.

---

## 10. Screen inventory

| # | Screen | Roles | Offline |
|---|---|---|---|
| 1 | Splash / session restore | all | — |
| 2 | Login | all | no |
| 3 | Staff dashboard (month summary) | Admin, Manager | yes 15 min |
| 4 | Member home (own balance) | Member | yes 15 min |
| 5 | Member roster | Admin, Manager | yes 30 min |
| 6 | Member detail | Admin, Manager | yes |
| 7 | Record meals (day grid) | Admin, Manager | yes **write-queued** |
| 8 | Meal history | all | current month only |
| 9 | Deposits list + record | Admin, Manager | yes **write-queued** |
| 10 | Deposit history | Member | yes |
| 11 | Expenses list + record | Admin, Manager | yes **write-queued** |
| 12 | Subsidies | Admin, Manager | read-only cached |
| 13 | Vendors | Admin, Manager | yes |
| 14 | Departments | Admin, Manager | yes |
| 15 | Reports / analytics / forecast | Admin, Manager | no (online only) |
| 16 | Meal menus + voting | all | no (online only) |
| 17 | Make a payment | Member | yes **write-queued** |
| 18 | Payment verification | Admin, Manager | yes |
| 19 | Claims (mine / review) | all / Admin+Manager | yes |
| 20 | Notifications | all | yes 5 min |
| 21 | Profile & settings | all | yes |
| 22 | Theme & terminology | all | yes |
| 23 | Institution settings | Admin (view: +Manager) | yes |
| 24 | Announcements | Admin | no (online only) |
| 25 | User management | Admin | yes |

---

## 11. Endpoint -> screen map

The authoritative contract is [`API.md`](API.md); this is the client-side wiring.

| Screen | Endpoint(s) |
|---|---|
| Splash | `GET /auth/me`, `GET /meta` |
| Login | `POST /auth/login`, `POST /auth/register` |
| Staff dashboard | `GET /dashboard?month=` |
| Member home | `GET /me/dashboard?month=` |
| Member meals | `GET /me/meals?month=` |
| Member deposits | `GET /me/deposits?month=` |
| Member analytics | `GET /me/analytics?months=` |
| Member roster | `GET /members?month=&search=&status=&per_page=` |
| Member detail | `GET /members/{member}?month=` |
| Record meals | `GET /meals/day?date=`, `POST /meals/day` |
| Meal history | `GET /meals?month=&member=&per_page=` |
| Deposits | `GET /deposits`, `POST /deposits`, `GET /deposits/export` |
| Expenses | `GET /expenses?month=`, `POST /expenses` |
| Vendors | `GET /vendors`, `POST /vendors` |
| Departments | `GET /departments`, `POST /departments` |
| Subsidies | `GET /subsidies`, `POST /subsidies`, `GET /subsidies/sources` |
| Reports | `GET /reports/meal`, `/reports/analytics`, `/reports/forecast`, `/reports/per-meal-rate` |
| Menus & voting | `GET /menus`, `GET /menus/{id}`, `POST /menus/{id}/vote`, `POST /menus`, `PATCH /menus/{id}/status` |
| Claims (mine) | `GET /claims`, `POST /claims` |
| Claims (review) | `GET /claims/review`, `PATCH /claims/{id}/approve`, `PATCH /claims/{id}/reject` |
| Make a payment | `GET /me/payments`, `POST /me/payments` |
| Payment verification | `GET /member-payments`, `PATCH /member-payments/{id}/approve`, `.../reject` |
| Notifications | `GET /notifications`, `GET /notifications/latest`, `POST /notifications/{id}/read`, `POST /notifications/read-all`, `DELETE /notifications/{id}` |
| Announcements | `POST /notifications/announce` |
| Institution settings | `GET /settings/institution`, `PUT /settings/institution`, `GET /settings/subsidy-sources` |
| Profile | `PATCH /auth/profile`, `PUT /auth/password` |
| Theme | `PUT /me/theme` |
| Sign out | `POST /auth/logout` |

**Auth headers on every call** (except the public ones):

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
X-Client-Platform: mobile
X-Client-Version: <semver>
```

---

## 12. Security

| Concern | Control |
|---|---|
| Token at rest | `flutter_secure_storage` (Keychain / EncryptedSharedPreferences). **Never** `SharedPreferences`, never Isar. |
| Token in transit | HTTPS only; `Env.assertSecureInRelease()` **throws** if a release build resolves to `http://`. |
| Logging | `Logger` redacts `Authorization`, `password`, `token`. Assert in debug that no secret reaches a log. |
| Screenshots | `FLAG_SECURE` (Android) / blur overlay (iOS) on the dashboard and member detail. |
| Root/jailbreak | Detect and warn; do not hard-block (this is a shared-mess app, not a bank). |
| Biometric unlock | Optional, gated behind `local_auth`, re-validating the session on resume. |
| Certificate pinning | Recommended for release builds (`badCertificateCallback` + a pinned fingerprint). |
| Inactive account | A `403` from the API surfaces the server's message without signing the user out. |
| SSA | Blocked at login, at `/auth/me`, and by the API group middleware (§2.3). |
| Tenant safety | A notification or cached payload for another `institution_id` is dropped. |

---

## 13. Testing

| Level | Tool | Coverage target |
|---|---|---|
| Unit — use cases, mappers, formatters | `flutter_test` | 90 % of `domain/` and `data/` mappers |
| BLoC | `bloc_test` | Every state transition, **including offline and queued paths** |
| Repository | `mocktail` + `DioAdapter` | All 9 failure mappings in §4.3 |
| Widget | `flutter_test` + golden files | `AppButton`, `StatusChip`, `MoneyText`, `EmptyState` |
| Integration | `integration_test` | Login -> record a day's meals -> offline queue -> reconnect -> sync |
| API contract | a generated client checked against `docs/API.md` | Drift between docs and client fails CI |

**The offline integration test is mandatory**, because it is the one path that
cannot be verified by hand:

```
enable airplane mode -> record meals -> assert the pending badge
-> disable airplane mode -> assert the queue drains and the server row exists
```

---

## 14. Build & release

| Flavour | Base URL | Notes |
|---|---|---|
| `dev` | `http://10.0.2.2:8000/api` | Android emulator -> host loopback. |
| `staging` | `https://staging.example.com/api` | |
| `prod` | `https://app.example.com/api` | HTTPS enforced; pinning on. |

```bash
flutter pub run build_runner build --delete-conflicting-outputs   # DI + freezed + Isar
flutter test
flutter build appbundle --flavour prod
flutter build ipa --flavour prod
```

CI: `dart format --set-exit-if-changed`, `flutter analyze --fatal-infos`,
`flutter test --coverage`, then build both artefacts.

---

## 15. Implementation checklist

Work top to bottom. Each box is a mergeable unit.

- [ ] `flutter create --org com.nomnomytics --platforms=ios,android nomnomytics_mobile`
- [ ] Add the dependencies from §1.1 to `pubspec.yaml`
- [ ] `analysis_options.yaml`: `flutter_lints` + `prefer_const_constructors`, `avoid_print`
- [ ] `core/config/env.dart` with the three flavours + `assertSecureInRelease()`
- [ ] `core/error/failures.dart` — the sealed hierarchy (§7.2)
- [ ] `core/network/` — the three interceptors, in the documented order (§4)
- [ ] `core/storage/` — `SecureStore` (token) + `IsarService` (cache + outbox + prune)
- [ ] `core/sync/` — `SyncQueue`, `SyncService` (single-flight), `SyncScheduler` (§5.3)
- [ ] `core/theme/` — tokens mirrored from §9.1, light + dark
- [ ] `core/notifications/push_service.dart` — FCM registration + deep-link routing (§6)
- [ ] `injection.dart` + `build_runner` codegen
- [ ] `go_router` with an `AuthGuard` redirect driven by BLoC auth state (§2.3)
- [ ] `features/auth/` — the full vertical slice FIRST; it is the template for every other feature
- [ ] `shared/nav/nav_items.dart` — ONE permission-filtered nav list (§2.2)
- [ ] Verify the SSA block end-to-end: an SSA account must fail at login, at `/auth/me`, and on every protected route
- [ ] Implement each feature from `features/` in §3, using §8 as the shape
- [ ] Write the offline integration test (§13) — it is the one path manual QA cannot cover
- [ ] Wire `POST /auth/devices` on login and on `onTokenRefresh`
- [ ] CI: format, analyze, test, build both artefacts (§14)

---

## 16. Agent completion criteria

The build is complete when ALL of the following hold:

| # | Criterion | How it is verified |
|---|---|---|
| 1 | Every screen in §10 exists and is reachable per its role. | Manual walkthrough per role. |
| 2 | No screen fetches a noun or currency it hard-coded. | Grep for literal `Student`, `Employees`, currency symbols. |
| 3 | A Member cannot reach any staff endpoint. | The API returns 403; the UI never renders the entry point. |
| 4 | An SSA cannot log in, and cannot use a pre-existing token. | §2.3, all three layers. |
| 5 | Meals and deposits can be recorded with no connectivity and sync on reconnect. | The offline integration test (§13). |
| 6 | No memory leak: every controller, subscription, timer and BLoC is disposed. | The §7.1 checklist, applied per screen; DevTools memory profile is flat across 20 navigations. |
| 7 | Every transport error renders through `showFailure` — no raw exception text reaches a user. | Grep for `catch` blocks that surface `e.toString()`. |
| 8 | `flutter analyze --fatal-infos` is clean; `flutter test` passes. | CI. |

---

## 17. Cross-references

| Topic | Where |
|---|---|
| Endpoint contract, envelopes, enums | [API.md](API.md) |
| Rate limits (60/min, 5/min login) | [API.md §3](API.md) |
| Token lifetime & revocation | [API.md §4](API.md) |
| Multi-tenant isolation rules | [API.md §5](API.md) |
| SSA mobile exclusion | [API.md §5](API.md), [FLUTTER_MOBILE_APP.md §1](FLUTTER_MOBILE_APP.md) |
| Backend stack & domain model | [SOFTWARE_ARCHITECTURE.md](SOFTWARE_ARCHITECTURE.md) |
| Role-by-role product behaviour | [USER_MANUAL.md](USER_MANUAL.md) |
| Design rationale (why, not how) | [FLUTTER_MOBILE_APP.md](FLUTTER_MOBILE_APP.md) |
-