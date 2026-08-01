# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

TikoSchool — a school management system for Moroccan private schools (students, classes, memberships, invoices, teacher payouts, attendance, results, in-app chat). Laravel 12 + Inertia.js 2 + React 18 monolith, MySQL 8, Redis, Laravel Reverb for websockets. UI strings are in French.

## Commands

```bash
composer dev          # server + queue:listen + vite, all three concurrently
npm run dev           # vite only
npm run build         # production assets
php artisan reverb:start   # websocket server (chat / notifications) — separate process
php artisan schedule:work  # scheduler (run-scheduler.bat is a local, gitignored helper)
./vendor/bin/pint     # PHP formatter (the only linter configured; no JS linter)
```

Copy `.env.example` — it lists every required key, including the ones the app dies without (`CLOUDINARY_*`, `REVERB_*`, `WASENDERAPI_API_KEY`). The **`VITE_*` values are read at build time and baked into the bundle**, so they must be set before `npm run build` or realtime silently fails in the browser with no server-side error.

### Tests

```bash
php artisan test                                  # full suite (Pest 3)
php artisan test --filter="teacher wallets"       # single test by name
php artisan test tests/Feature/InvoicePricingTest.php
```

The suite runs against **MySQL**, not sqlite — [phpunit.xml](phpunit.xml) pins `DB_DATABASE=tikoschool_test`, which must exist locally:

```sql
CREATE DATABASE tikoschool_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

This is deliberate: the app depends on `JSON_LENGTH`/`JSON_CONTAINS`/`DATE_FORMAT`, invoice triggers, and MySQL treating double-quoted tokens as string literals. Never "simplify" the test config to sqlite — and never let those `DB_*` env entries be removed, since Feature tests use `RefreshDatabase` and would drop the working database.

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs the suite against MySQL 8 and prints `route:list --columns=method,uri,name,middleware`.

## Architecture

### Request flow

Every page is an Inertia response: controller → `Inertia::render('Menu/PaymentsPage')` → [resources/js/Pages/](resources/js/Pages/)`Menu/PaymentsPage.jsx`. The name is relative to `Pages/` — do **not** include the `Pages/` prefix; `app.jsx` prepends it. Pages set their own `Page.layout` (usually `DashboardLayout`); `app.jsx` deliberately does **not** import a layout, keeping it out of the eager entry chunk. `@/` aliases `resources/js/`.

[HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php) shares `auth`, `flash`, `chatContacts`, `activeSchool`, `unreadCount`. The expensive props are closures so partial reloads don't pay for them — keep them lazy.

### Roles and authorization

Three roles on `users.role`: `admin`, `teacher`, `assistant`. There are **no policies and no Gates** — `app/Policies/` held 12 unregistered `return false` stubs and was deleted; don't recreate it without writing the bodies in the same change. Authorization is two layers:

1. **Route level** — [`AdminMiddleware`](app/Http/Middleware/AdminMiddleware.php) (what routes/web.php currently uses; it *redirects* to `/dashboard`, so a denied XHR looks like success to the frontend) and [`RequireRole`](app/Http/Middleware/RequireRole.php) `::class.':admin,assistant'`, which aborts 403 and supports multiple roles. `RequireRole` is not yet used by any route — prefer it for new ones.
2. **Object level** — [App\Support\SchoolScope](app/Support/SchoolScope.php). Controllers `findOrFail()` ids from the route, so without an explicit `SchoolScope::authorizeStudent()` / `authorizeClass()` / `authorizeSchool()` call any staff member can read any record by guessing an id. Add the call whenever you add a route that takes a student/class/school id.

> **Trap when adding an authorization check.** 17 of the ~20 controllers wrap their body in
> `try { ... } catch (\Exception $e) { return redirect()->back()...; }`. `abort(403)` throws
> `HttpException`, which **extends `\Exception`** — so a generic catch swallows the denial and
> turns it into a harmless redirect or a 500. Either put the check **before** the `try`, or add
> `catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }`
> ahead of the generic handler. Both patterns already exist in the codebase
> (`PerformanceController::show` and `ResultsController::updateGrade` respectively).
> A denial that silently 302s instead of 403ing is very easy to mistake for a working guard.

Teacher and assistant identity joins `users` to `teachers`/`assistants` **by email**, not by foreign key. [ProfileUpdateRequest](app/Http/Requests/ProfileUpdateRequest.php) blocks re-pointing an email at someone else's record; keep that guard if you touch profile editing.

Admin "view as" impersonation goes through [App\Support\Impersonation](app/Support/Impersonation.php). The presence of the `admin_user_id` session key is **not** proof of admin rights — while impersonating, the current user is deliberately less privileged. Use `Impersonation::hasVerifiedAdmin()`.

### Money: teacher wallets

`teachers.wallet` is a cached projection of the append-only `teacher_wallet_entries` ledger. **All movement goes through [TeacherWalletService](app/Services/TeacherWalletService.php)** `credit()`/`debit()` — row-locked, transactional, and idempotent per **`(teacher_id, invoice_id, month, reason)`** via a DB unique constraint. Direct `increment('wallet')`/`decrement('wallet')` anywhere else fails [ArchitectureTest](tests/Feature/ArchitectureTest.php). `wallet:check` asserts nightly that every wallet reconciles; it exits non-zero on drift.

Two consequences of that key worth knowing before you add a call site:

- It is keyed on `invoice_id`, **not** the payment-record id, because the first credit happens before the payment record exists.
- MySQL treats NULLs as distinct, so movements with a **null `invoice_id` are exempt** from the constraint. That is deliberate — manual payouts and adjustments legitimately repeat. If you add a credit that *must* be deduplicated, it has to carry an `invoice_id` and a `month`.

`debit()` clamps at zero: a negative balance permanently blocks payouts elsewhere, so an over-reversal must never be allowed to create one.

The payout pipeline lives in [TeacherMembershipPaymentService](app/Services/TeacherMembershipPaymentService.php) (1400 lines — the densest file in the repo) and `teachers:process-monthly-payments`. `payouts:audit` is read-only and scheduled daily; `payments:check-consistency --fix` is deliberately **disabled** because it repairs toward the current payout formula.

### Domain chain

`Offer` (price per subject-set) → `Membership` (student + offer + `teachers` JSON column) → `Invoice` (months, `billDate`, `amountPaid`, `rest`) → payment credits teacher wallets. `Membership` and `Invoice` both use `SoftDeletes`, and `Invoice::membership()` must stay `->withTrashed()` — otherwise deleting an invoice whose membership was trashed silently skips the wallet reversal. Pricing rules are in [InvoicePricingService](app/Services/InvoicePricingService.php).

### Scheduling

All scheduled tasks live in `->withSchedule()` in [bootstrap/app.php](bootstrap/app.php). `app/Console/Kernel.php` must **not** exist (Laravel 12 never loads it; a stale copy defined a second, conflicting schedule — asserted by a test). Every money-touching task carries `->withoutOverlapping()->onOneServer()`; closure tasks additionally need `->name()` before `withoutOverlapping()` or the app throws at boot.

### Realtime

Reverb + Laravel Echo. `resources/js/echo.js` builds the client **lazily** via `getEcho()` and dynamically imports `laravel-echo`/`pusher-js` — don't reintroduce a top-level Echo construction, it opened a websocket on `/login`. Channels are in [routes/channels.php](routes/channels.php); they must be **private** (public channels are never authorized by Laravel).

## Conventions and traps

- **Route ordering** — wildcard `show` routes are registered before literal ones like `/create`. Constrain ids with `->where('param', '[0-9]+')`, or the literal route becomes unreachable and `Route::fallback` silently redirects to `/dashboard`.
- **The auth group** — routes accidentally placed below the closing brace of `Route::middleware('auth')->group(...)` in [routes/web.php](routes/web.php) are anonymous. [RouteProtectionTest](tests/Feature/RouteProtectionTest.php) enumerates the real route table and fails on any unprotected route; adding to its `PUBLIC_ROUTES` allowlist needs justification.
- **`DB::raw` with double quotes** is a string literal in MySQL, not a column reference — it matched zero rows silently. Blocked by ArchitectureTest.
- **`DB::enableQueryLog()`/`getQueryLog()`** in app code is blocked by ArchitectureTest (it accumulated bound params with real student and financial data into the logs).
- **`config/app.php` must not declare a `providers` array** — that pins the Laravel 10 provider list and suppresses providers added by later releases.
- **Column naming is inconsistent by legacy**: `students` and `invoices` use camelCase (`schoolId`, `classId`, `levelId`, `billDate`, `totalAmount`), most other tables use snake_case. Match the table you're touching; don't rename.
- Comments in this codebase document bugs that actually shipped. Treat a long explanatory comment as a constraint, not noise.

## Known gaps — not yet fixed

Don't rediscover these, and don't assume they're safe because they're old.

- **Cloudinary credentials are read with `env()` at runtime** in `TeacherController`, `StudentsController` and `AssistantController` (`getCloudinary()`). `env()` returns **null once `config:cache` has run**, which the production entrypoint always does — so image uploads fail in production and work locally. There is no `config/cloudinary.php`. Fixing it means adding that config file and reading `config('cloudinary.*')`; the same three ~30-line uploaders are also copy-pasted verbatim.
- **`MAIL_MAILER=log`.** Password reset *works* (the broker was misconfigured and returned a 500 — that is fixed), but nothing is delivered until a real transport is configured. Reset URLs with live tokens land in the log file.
- **Only `User` declares `$hidden`.** Every other model serializes every column into the Inertia page props — student medical fields (`hasDisease`, `diseaseName`, `medication`), guardian phone numbers, assistant salaries. Adding `$hidden` is safe; check the React consumer first.
- **Money columns on `Invoice` are uncast** (`totalAmount`, `amountPaid`, `rest`, `partialMonthAmount`) so they arrive as floats. `Transaction` and `TeacherMembershipPayment` do use `decimal:2`. Round explicitly when comparing.
- **Historical payout drift.** The formula is correct going forward and the ledger prevents recurrence, but existing over/under-payments were never corrected — that needs a product decision. `payouts:audit` quantifies it.
- **Single-tenant strings are hardcoded** in `AttendanceController` (school name, phone, email, Instagram URL, opening hours) inside WhatsApp templates, in a multi-tenant product.
- **`SchoolController`'s `performance` field returns `null`.** It was `rand(60, 100)` presented to users as a real metric; it needs a real definition or removal from the page.
- **There is no policy/Gate layer at all.** The 12 `return false` stubs in `app/Policies/` were deleted as dead scaffolding; the 20 unused `authorize(): return false` FormRequests went with them. Building real object-level authorization is a project, not a quick win — `App\Support\SchoolScope` is what currently carries it.

## Operational commands

```bash
php artisan payouts:audit                 # read-only: over/underpaid teachers, orphans, duplicates
php artisan payouts:audit --csv=path.csv  # per-teacher breakdown
php artisan wallet:check                  # ledger vs cached wallet; non-zero exit on drift
php artisan wallet:check --seed           # ONE TIME after the ledger migration, per DATABASE
php artisan teachers:cleanup-duplicates   # required before the unique-key migration can apply
```

`wallet:check --seed` records an opening-balance entry for wallets that predate the ledger. Skip it and every teacher reads as fully drifted. It is idempotent.

**Per database, not per release.** `migrate` + `wallet:check --seed` have to be re-run against any database the app is newly pointed at — a restored dump, a promoted replica, a cloned staging copy, or just a changed `DB_DATABASE`. Nothing detects the omission; it surfaces as nightly false drift alerts and, if the idempotency index is missing, as double wallet credits. [DEPLOYMENT_RUNBOOK.md](DEPLOYMENT_RUNBOOK.md) §9 is the checklist.

## Deployment

Docker Compose (nginx / php-fpm / mysql / redis) is the current setup — see [docker-compose.yml](docker-compose.yml). [DOCKER_TO_NATIVE_MIGRATION.md](DOCKER_TO_NATIVE_MIGRATION.md) plans a move to native Ubuntu services. [DEPLOYMENT_RUNBOOK.md](DEPLOYMENT_RUNBOOK.md) is the ordered procedure for the hardening release, including the one-time wallet opening-balance seeding that must run right after migrating.

`trustProxies` in [bootstrap/app.php](bootstrap/app.php) is restricted to loopback/private ranges — add CDN ranges there rather than reverting to `at: '*'`, which made `$request->ip()` caller-controlled.
