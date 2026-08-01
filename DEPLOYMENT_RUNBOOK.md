# Deployment runbook — production hardening release

Everything below is **server-side work that cannot be done from the repo**. Do the steps in
order; several have hard dependencies on the ones before them.

> On the Docker stack every `php artisan` command below runs inside the app container —
> `docker compose exec php php artisan ...`. Under a native deploy, run them from the project
> root as the web user. The `mysql`/`mysqldump` calls likewise become
> `docker compose exec mysql ...` (MySQL is no longer published on the host).

---

## 0. Before you start

Take a database backup and **verify you can restore it**. Several steps below add constraints
and one of them refuses to run if the data is inconsistent.

```bash
mysqldump -u root -p --single-transaction --routines --triggers tikoschool > backup_$(date +%F).sql
```

**Keep `--triggers`.** `invoices` carries two offer-consistency triggers owned by migration
`2025_09_23_234107`. A dump taken without them restores a database that looks complete and
silently accepts offer/price mismatches — and no migration will put them back, because the
migration is already recorded as run.

> There is no automated backup in `docker-compose.yml`. Adding one is the single highest-value
> thing left on the infrastructure list.

### 0.1 Pre-flight — will the migrations abort?

Two migrations refuse to run on inconsistent data. Find out **before** the maintenance window,
not during it. All three queries below must return the stated result:

```sql
-- 0 rows. Otherwise `add_unique_key_to_teacher_membership_payments` throws (see step 4).
SELECT invoice_id, teacher_id, teacher_subject, COUNT(*) AS copies
FROM teacher_membership_payments
WHERE invoice_id IS NOT NULL
GROUP BY invoice_id, teacher_id, teacher_subject
HAVING copies > 1;

-- 0 rows. `update_classes_add_school_and_composite_unique` adds UNIQUE(name, school_id).
SELECT name, school_id, COUNT(*) FROM classes GROUP BY name, school_id HAVING COUNT(*) > 1;

-- exactly 2 rows: check_offer_consistency_before_insert / _before_update on `invoices`.
SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE();
```

`update_classes_add_school_and_composite_unique` also drops `classes_name_unique`. The
`try/catch` around that drop is **decorative** — `dropUnique()` only queues a command inside the
Blueprint closure and the SQL runs after it returns, so the exception escapes the handler. If
that index is already gone the migration fails. Confirm with `SHOW INDEX FROM classes`.

---

## 1. Rotate the database credentials — **required**

A plaintext MySQL password was committed in `DOCKER_TO_NATIVE_MIGRATION.md` and pushed to
GitHub in commit `db0c602` — recover the exact value with
`git show db0c602:DOCKER_TO_NATIVE_MIGRATION.md` if you need to confirm what to revoke. It has
been redacted from the working tree, but **it is still in git history**, so it must be treated
as public. The same value was also the MySQL `root` password.

(Not repeated here on purpose: this file is committed, and re-publishing a live credential in
the document that tells you to rotate it defeats the point.)

```sql
ALTER USER 'tikoschool'@'%'         IDENTIFIED BY '<new-app-password>';
ALTER USER 'root'@'localhost'       IDENTIFIED BY '<new-DIFFERENT-root-password>';
FLUSH PRIVILEGES;
```

`docker-compose.yml` now **requires** these and refuses to start without them — the old
`${DB_PASSWORD:-secret}` defaults are gone, and the app user and root user are no longer the
same variable:

```dotenv
DB_PASSWORD=<new-app-password>
DB_ROOT_PASSWORD=<new-DIFFERENT-root-password>
REDIS_PASSWORD=<new-redis-password>      # Redis previously had no password at all
```

Scrubbing git history (`git filter-repo`) is optional — rotation is what actually matters.

---

## 2. Production `.env`

```dotenv
APP_ENV=production
APP_DEBUG=false                  # compose pins APP_ENV but never APP_DEBUG — check it
LOG_LEVEL=warning
LOG_STACK=daily                  # `single` grew one file to 119 MB
LOG_DAILY_DAYS=14
SESSION_SECURE_COOKIE=true
REVERB_ALLOWED_ORIGINS=tiko.school,www.tiko.school   # was hardcoded to '*'
```

> **Ship `SESSION_SECURE_COOKIE=true` on its own.** If HTTPS is not terminating on every
> path, a Secure cookie becomes undeliverable and logs out every user with no visible cause.
> Verify the 443 vhost serves every route, including the `/app/` Reverb proxy, first.

Mail is still `MAIL_MAILER=log`. Password reset now *works* (it was returning a 500 — the
broker was misconfigured), but the message only reaches the log file until you configure a
real transport.

---

## 3. Deal with the existing log file

`storage/logs/laravel.log` reached **119 MB** and contains student financial data and
password-reset URLs with live tokens. Decide: archive to secure storage, or destroy.

```bash
# after you have decided
: > storage/logs/laravel.log
```

---

## 4. Baseline the payout data — **before** migrating

```bash
php artisan payouts:audit --csv=storage/app/payout-audit-$(date +%F).csv
```

Keep this output. Once payout behaviour changes you can no longer separate the fix's effect
from historical drift. It reports overpaid/underpaid teachers, orphaned records, duplicate
payout rows and negative wallets.

**If it reports duplicates**, resolve them before step 5 — the new unique-key migration
deliberately refuses to run rather than silently merging financial rows:

```bash
php artisan teachers:cleanup-duplicates
```

The only two lines that **block** step 5 are `Duplicate (invoice,teacher,subject)` and, if you
intend to act on it, `Teachers with a negative wallet`. Underpayment, overpayment and orphaned
records are historical state — record them and move on; they are the input to the remediation
decision at the bottom of this document, not a reason to stop.

---

## 5. Migrate

```bash
php artisan migrate --force
```

Three new migrations:

| Migration | What it does |
|---|---|
| `create_teacher_wallet_entries_table` | Append-only wallet ledger with an idempotency unique key |
| `add_unique_key_to_teacher_membership_payments` | One payout record per (invoice, teacher, subject). **Aborts if duplicates exist.** |
| `add_hot_path_indexes` | Indexes for the columns actually filtered/sorted on |

> `docker/php/entrypoint.sh` no longer swallows migration failures (`|| true` removed), so a
> failed migration now aborts the deploy instead of booting against a half-migrated schema.
> That is intentional.

---

## 6. Seed wallet opening balances — **one time, right after step 5**

Existing wallets predate the ledger, so without this every teacher looks like they have
drifted by their entire balance.

```bash
php artisan wallet:check --seed     # records a one-off opening-balance entry per teacher
php artisan wallet:check            # must now report no drift
```

The second command must print *All teacher wallets reconcile against the ledger* and exit 0.
If it doesn't, stop — the ledger is now the source of truth for payouts and a mismatch here
means the seed did not cover every teacher.

`wallet:check` runs nightly at 04:30 and exits non-zero on drift, so hook it into whatever
monitors your scheduler.

---

## 6b. Clear the caches

`SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` are all `database`, so the session,
cache and job tables live **inside the database you just migrated**. Combined with
`config:cache` in the entrypoint, a stale cached config plus a swapped database is how you get
"it connects but nothing works".

```bash
php artisan optimize:clear
php artisan config:cache            # production only, after the .env is final
```

Everyone is logged out — sessions belong to the old database. Expect it; don't debug it.

---

## 7. Force a password reset for all users

Every account edited through the admin UI had its password silently set to a known literal
(`UserController` did this unconditionally on every save). Assume all admin-edited accounts
are compromised.

---

## 8. Rebuild and restart

```bash
docker compose build --no-cache
docker compose up -d
```

The frontend build now needs `VITE_REVERB_*` present **at build time** — they are baked into
the bundle. `.env.example` documents every required key (it was missing 22 of them).

---

## 9. Any time you point the app at a different database — repeat 0.1 and 4-6b

**Steps 4, 5 and 6 are per-database, not per-release.** Restoring a dump into a fresh schema,
promoting a replica, cloning production down to staging, or just editing `DB_DATABASE` all put
you in front of a database that has never had them applied. Nothing in the app detects this;
the failures are quiet and they are all in the money path.

| Symptom | Cause |
|---|---|
| `wallet:check` reports every teacher as fully drifted, every night at 04:30 | step 6 was never run on **this** database |
| A payment credits a wallet twice | `teacher_wallet_entries` exists but the `teacher_wallet_entries_idempotency` unique key does not — the ledger only deduplicates because the **database** rejects the second row |
| Invoices accept an offer/price mismatch | the two `invoices` triggers were lost to a `mysqldump` without `--triggers` (§0) |
| `migrate` aborts with *"Cannot add the unique key"* | duplicate payout rows in the restored data — §0.1, then `teachers:cleanup-duplicates` |

```bash
# 1. verify (§0.1)  2. baseline (§4)  3. then:
php artisan migrate --force
php artisan wallet:check --seed     # idempotent per teacher — safe to re-run
php artisan wallet:check            # must exit 0
php artisan optimize:clear
```

`wallet:check --seed` is idempotent, so running it on a database that already has opening
balances is a no-op. When in doubt, run it — the failure mode of skipping it (nightly false
drift alerts that get muted, hiding a real one later) is worse than the failure mode of
repeating it (nothing).

Re-run the §4 baseline audit against the new database too. The old CSV describes the old
database; carrying it forward will make a restore look like a payout regression.

> **Rehearse on a copy.** This whole sequence — 0.1 → backup → audit → migrate → seed →
> verify → `php artisan test` — was validated end to end against a restored copy before being
> written down. It takes about five minutes and it is the only way to find out that a
> constraint aborts *before* you are in a maintenance window.

---

## What changed operationally

- **nginx**: HSTS, Referrer-Policy and Permissions-Policy added; `X-Forwarded-For` is now
  only trusted from private ranges (it was `0.0.0.0/0`, so any client could spoof its IP and
  bypass login throttling); rate limiting on `/login` and `/forgot-password`; `/storage/`
  served via an alias (the nginx image has no `public/storage` symlink, so those files 404'd).
- **compose**: healthchecks on `php` and `nginx`, and nginx now waits for `service_healthy`
  rather than merely "container created" — that was the cause of 502s on every deploy.
  MySQL and Redis are no longer published on the host.
- **queue worker**: `--tries=1` removed. It was overriding each job's own `$tries`/`$backoff`,
  so a single transient WhatsApp API error sent the job straight to `failed_jobs`.
  Added `--max-time=3600` so deployed code is picked up (opcache runs with
  `validate_timestamps=0`).
- **scheduler**: every task now has `withoutOverlapping()` and `onOneServer()`. There were no
  overlap guards at all, and the monthly payroll job is manually triggerable.

## Behaviour changes users will notice

1. **Impersonation is now a true "view as".** While impersonating, an admin sees exactly what
   that user sees. Browsing other teachers' profiles requires switching back first.
2. **Password policy** is 12 characters with mixed case and numbers (was 8, unenforced).
3. **Teacher wallet is no longer editable** on the teacher form. It is derived from the
   ledger; the form used to round-trip a stale value and silently revert earnings that
   arrived between opening and saving the form. Adjustments go through a payout transaction.
4. **Invoice totals are computed server-side.** A discount below the computed price is still
   honoured and logged; a value above it is ignored.
5. **School detail pages now show real numbers.** They reported 0 students because the query
   used `DB::raw('"schoolId"')`, which MySQL reads as a string literal.
6. The "performance" column on the school page returns `null` — it was `rand(60, 100)`
   presented to users as a real metric. It needs a real definition or removal.

## Still open

- **Mail transport** — password reset works but is undeliverable until configured.
- **Automated, restore-tested backups** — none exist.
- **Error monitoring** (Sentry or similar). Alert on `Log::error` from
  `TeacherMembershipPaymentService`, on wallet drift, and on negative wallets.
- **Historical payout correction.** The formula is fixed going forward and the ledger prevents
  recurrence, but existing over/under-payments need a policy decision. Use the step-4 CSV.
