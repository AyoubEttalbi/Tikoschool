# Second audit + review of `AUDIT_2026-08-07.md`

**Date:** 7 August 2026
**Targets:** `tiko.school` VPS and branch `local` (`8c68e33`)
**Method:** four ECC specialists (security, PHP/Laravel, database, performance) reviewed the repo
independently, without being shown the existing audit. Every claim I report below I then re-checked
myself against the **live production server** — live route table via `route:list --json`, live
`information_schema` for indexes, live `SHOW VARIABLES`, live container cgroup stats, live cron and
supervisor config, and outside-in HTTP probes.

I disagree with the existing audit in four places and found ten things it missed. Everything else I
confirm.

**Nothing here has been fixed. Diagnosis only.**

---

## 1. Verdict at a glance

| | Count |
|---|---|
| Findings I **confirm** | 31 |
| Findings I **correct or downgrade** | 4 |
| Findings the audit **missed** | 10 |

The existing audit is good work. Its core thesis — *authorization is the emergency, the ledger design
is sound but has bypasses, the DB problems are latent* — is correct and I reached it independently.
Its weaknesses are all the same shape: **it stopped measuring one step too early.** It named five
unguarded routes when the live table has 68; it named one table with duplicate indexes when there are
two; it asserted a nightly alarm exists without checking where the alarm goes.

---

## 2. What I agree with

Confirmed by my own independent verification. Grouped, not repeated in full.

### Infrastructure — agree, all verified

| Audit § | Finding | My verification |
|---|---|---|
| 3.1 | SSH: root login + password auth on, no ufw, no fail2ban | `sshd -T`: `permitrootlogin yes`, `passwordauthentication yes`, `x11forwarding yes`. `ufw` inactive, `iptables -P INPUT ACCEPT`, fail2ban absent. **1,362** failed attempts in 24h. |
| 3.1 | Not breached | **0** `Accepted password` in 30 days. All accepts are `publickey`, all from Moroccan ISP ranges. Exactly 2 authorized keys, both named. **Zero** non-root shell users (`awk` on `/etc/passwd` returned empty). Cron dirs hold only `certbot`, `e2scrub_all`, `sysstat`. I agree: clean. |
| 3.2 | No automated backups | No crontab, no restic/borg/duplicity binary, no backup timer in `systemctl list-timers`. The only file in `/root/backups/` is today's manual dump. Confirmed. |
| 3.3 | Kernel reboot pending | `/var/run/reboot-required` present, running `6.8.0-124-generic`, 40d uptime. `unattended-upgrades` active, **0** pending security packages. Agree with both halves. |
| 3.5 | Docker logs unbounded, 7.6 GB build cache | No `/etc/docker/daemon.json`. `docker system df`: **7.642 GB** reclaimable build cache on a 48 GB disk at 46%. Confirmed. |
| 3.6 | No CSP | Confirmed by live `curl -I`. HSTS, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy all present; `content-security-policy` absent. |
| 3.7 | Version disclosure | `server: nginx/1.31.3`, `x-powered-by: PHP/8.2.33`. `php -i` confirms `expose_php => On`. |
| 3.9 | TLS correct | Agree, no action. |
| 4 | Keep Docker | Agree, and for the reasons given. `dockerd` 390 MB + `containerd` 44 MB is ~12% of RAM; 0 OOM events; swap 88 MB of 4 GB. The bottleneck is 1 vCPU, which a native migration does not fix. Shelving `DOCKER_TO_NATIVE_MIGRATION.md` is the right call. |

### Authorization — agree on substance

§5.1–5.5 are all correct. My security specialist reached the same five findings independently, and I
verified the two that can be checked from outside the code:

- **§5.1** — confirmed against the live route table: `DELETE students/{student}` and
  `DELETE invoices/{invoice}` carry only `web, Authenticate`, while `DELETE schools/{school}` on the
  adjacent line carries `AdminMiddleware`. An omission, not a design.
- **§5.5** — the catch-all trap is real and is the highest-leverage single fix in either document. I
  independently counted **76 `catch (\Exception $e)` blocks across 17 controllers**, and confirmed
  `ResultsController` is the *only* file in `app/` containing `HttpExceptionInterface`. Agree that
  this must land before any `abort(403)` is trusted.
- **§5.2 / §5.3** — confirmed. `AssistantController` contains no `SchoolScope`, no role check and no
  `abort()` anywhere in the file, while its *write* routes are admin-guarded. Read-open, write-closed
  is backwards.

One refinement on **§5.4**: the audit calls the error-body reflection an "information-disclosure
path." Precisely, it reflects the **caller's own** submitted payload back to the caller, which is not
a cross-user leak. What it genuinely leaks is `$e->getMessage()` — raw internal exception text from
`TeacherMembershipPaymentService`. Still worth fixing, still HIGH-adjacent, but the mechanism in the
audit is described one notch too alarmingly.

### Money — agree, including the correction

**§6.1 confirmed by my own grep**, not taken on trust:

```
app/Http/Controllers/TransactionController.php:1628:  $teacher->wallet += $transaction->amount;
app/Http/Controllers/TransactionController.php:1647:  $teacher->wallet -= $transaction->amount;

tests/Feature/ArchitectureTest.php:39:  if (preg_match("/(increment|decrement)\(\s*'wallet'/", $code)) {
```

The guard rail genuinely has a hole exactly the shape of the real bug. Confirmed.

**And I confirm the audit's `[CORRECTED]` call — the drift is latent, not active.** Live data:

```
transactions:  id 2-5, all type=payment, all DATE(created_at) = 2026-08-05
ledger:        first entry 2026-08-07 (today's --seed)
```

Those four payments predate the ledger and were absorbed into seeded opening balances. The audit was
right to overrule its agent here.

> My own PHP specialist claimed this bug is *"very likely already firing nightly drift alerts on the
> 15 live teachers."* **That is wrong on both counts** and I am not repeating it: the dates prove no
> drift exists yet, and — see §4.1 below — there are no alerts at all.

§6.2 (undefined `$invoice`), §6.3 (swallowed reversal), §6.4 (unlocked bookkeeping columns) and §6.5
(uncast money columns, `wallet` in `$fillable`) — all confirmed by independent reading.

### Database, features, frontend — agree

- **§7.1 confirmed live inside the container**: the running process is
  `php /app/artisan queue:work --timeout=90 --max-time=3600 --sleep=3` — **no `--queue` flag**, and
  `jobs`/`failed_jobs` are both 0 rows. Parent WhatsApp notifications have never been delivered.
- **§8.2 confirmed against `information_schema`** — six indexes, four redundant. The audit's list of
  four to drop is exactly right.
- **§8.1, §8.3, §8.5** (stats recompute, PHP-side pagination, dashboard N+1) — all confirmed.
- **§9.1 correction confirmed.** Live `.env` really is `CACHE_STORE=redis`, `SESSION_DRIVER=database`,
  `QUEUE_CONNECTION=database`. The audit was right to overrule its performance agent, and right that
  keeping the queue on the database is the correct trade given Redis has no persistence configured.
- **§9.2 chunk sizes reproduced by a fresh build.** `PaymentsPage` 413 kB raw / **127 kB** gzip (audit
  said 126 kB — same measurement). Entry chunk 308 kB / 102 kB, and it really does contain only
  React/ReactDOM/Inertia/axios. The `SingleAssistantPage` vs `SingleTeacherPage` lazy-load asymmetry
  is real.

---

## 3. What I disagree with

### 3.1 §3.4 — the php container memory number is overstated ~2.6×

The audit reports php at **358 MB against a 512 MB cap (70%)** and "~150 MB headroom."

Measured live, both ways:

```
docker stats  (cgroup — the number that triggers OOM-kill):   136.9 MiB / 512 MiB   26.7%
host RSS sum across the container's PHP processes:            359 MB
```

The 359 MB figure double-counts shared memory. `opcache.memory_consumption=128M` +
`opcache.jit_buffer_size=64M` is a **192 MB shared segment mapped into every one of the 5 FPM workers
plus Reverb plus the queue worker** — so it is counted seven times in an RSS sum, and once by the
cgroup. The kernel OOM-killer reads the cgroup number.

**Real utilisation is 27%, not 70%. Real headroom is ~375 MB, not ~150 MB.**

*The recommendation still stands* — dropping JIT to reclaim 64 MB is right, and the theoretical
5 × 256 MB worst case is a genuine tail risk worth `pm = dynamic`. But this is scheduled maintenance,
not the near-miss the audit's framing implies, and it does not belong at position 08 ahead of things
that are actively broken.

### 3.2 §5.1 — "five resource routes" understates the exposure by roughly 13×

I dumped the live route table (`route:list --json`, 197 routes) and classified every one by the
middleware actually attached. The complete set of guard middleware in production is
`AdminMiddleware` (91 routes), `CanViewTeacherProfile` (2), `CheckImpersonation` (1), `RoleRedirect`
(1). `RequireRole` is attached to **zero** routes, confirming it is dead code.

After excluding genuinely role-agnostic self-service routes (login, logout, profile, password,
dashboard, inbox, messaging, announcements):

> **68 authenticated routes carry no role guard of any kind — not 5.**

The audit's five resources are a subset. See §4.4 for the money and PII routes it never named.

### 3.3 §6.1 / §2 — the stated safety net does not exist

The audit's mitigation narrative is that the latent wallet bug "fires on the next payout, and
`wallet:check` alarms at 04:30 the following morning." I checked where that alarm goes.

It goes nowhere. Three independent confirmations:

```
container crontab (/etc/crontabs/www-data):
  * * * * * php /app/artisan schedule:run >> /dev/null 2>&1     ← stdout AND stderr discarded

bootstrap/app.php:71-74 —  wallet:check has ->withoutOverlapping()->onOneServer()
                           and NO ->onFailure() / ->emailOutputOnFailure() / ->appendOutputTo()

app/Console/Commands/CheckWalletLedger.php — reports drift via $this->error() and
                           returns self::FAILURE. Zero Log:: calls in the file.
```

`MAIL_MAILER=log`, so even adding `emailOutputOnFailure` today would write to a file nobody reads.

This is not a nitpick — it changes the risk rating of the entire §6 section. Every money finding in
the audit is implicitly rated "recoverable because we'd detect it within 24h." That detection layer
is not connected. I have promoted it to a finding of its own (§4.1).

### 3.4 §8.2 — correct, but presented as the only instance

The audit enumerates the duplicate indexes on `membership_monthly_stats` precisely and correctly. It
then moves on, implying that is the extent of the problem. It is not — see §4.3, which is on a table
that actually takes write volume.

---

## 4. What the audit missed

### 4.1 `[CRITICAL]` `[VERIFIED]` The wallet drift detector has no alerting channel

Full evidence in §3.3 above. The single most important thing in this document.

`wallet:check` is the control that makes every other money finding survivable. It runs nightly, it
correctly exits non-zero on drift, and **its exit code and output are both discarded.** Nobody would
learn about drift except by manually running the command.

**Fix.** Add `->onFailure(fn () => Log::critical(...))` in `bootstrap/app.php`, drop the `>> /dev/null
2>&1` from the container crontab so cron output reaches the container log, and add a `Log::critical`
inside `CheckWalletLedger` on the failure branch. Configure a real `MAIL_MAILER` or a webhook so it
reaches a human. This is ~30 minutes and it protects everything in §6.

### 4.2 `[HIGH]` `[VERIFIED]` `sync_binlog=1` silently cancels the durability tuning

The audit flagged binary logging under §8.8 but marked it `[SOURCE]` and inferred it was on from a
config line. I checked:

```
log_bin                          ON
sync_binlog                      1        ← the audit never mentions this
innodb_flush_log_at_trx_commit   2
binlog_expire_logs_seconds       2592000  (30 days)
```

`innodb_flush_log_at_trx_commit = 2` was set deliberately to avoid an fsync per commit. But
`sync_binlog = 1` fsyncs the **binlog** on every commit, so the fsync is still happening — the tuning
buys nothing while still accepting its ~1s-of-commits-on-host-crash risk. Worst of both.

There is no replica and no PITR tooling in the repo, so nothing consumes the binlog.

**Fix.** `skip-log-bin` if PITR is genuinely not wanted; otherwise `sync_binlog=0` to match the risk
profile already accepted. Either way, stop paying for a durability guarantee that is being discarded
one layer down.

### 4.3 `[HIGH]` `[VERIFIED]` Two redundant UNIQUE constraints on `attendances` — the write-heaviest table

My first duplicate-index sweep missed this because the two constraints have the **same five columns in
a different order**, so a `GROUP BY` on the column list does not match them. Grouping by column *set*
finds it:

```
attendances_unique_full                                 UNIQUE (student_id, classId, teacher_id, subject, date)
attendances_student_class_date_teacher_subject_unique   UNIQUE (student_id, classId, date, teacher_id, subject)
```

From migrations `2025_07_26_000001` and `2025_08_13_155851`. The second migration drops the *older
three-column* constraint but never drops `attendances_unique_full`. Both enforce the identical
business rule, and both are maintained on every insert into the one table that takes daily
per-student-per-class writes.

`membership_monthly_stats` (the audit's finding) will never exceed ~120 rows/year. `attendances` grows
by roughly `students × classes` **per school day**. This is the duplicate-index finding that actually
costs something.

**Fix.** Drop `attendances_unique_full`. Keep the one leading `(student_id, classId, date, ...)`,
which matches the `attendances_date_index` access pattern.

### 4.4 `[HIGH]` `[VERIFIED]` Unguarded money and PII routes the audit never named

From the 68 in §3.2, these are the ones that matter and appear nowhere in `AUDIT_2026-08-07.md`:

| Route | Defined at | Why it matters |
|---|---|---|
| `GET /cashier`, `GET /cashier/daily` | `routes/web.php:325,333` | Daily till / cash position. Inline closures, `auth` only. |
| `GET /teacher-earnings-report` | `routes/web.php:439` | Every teacher's monthly earnings. |
| `GET /teacher-invoice-breakdown` | `routes/web.php:442` | Per-invoice payout breakdown. |
| `GET /teacher-invoices/download-pdf`, `POST /teacher-invoices/bulk-download` | in the same block | Bulk payout documents. |
| `GET /users` | `routes/web.php:311` | Full staff directory. |
| `GET /api/invoices/{id}` | `routes/web.php:418` | JSON invoice by id — clean IDOR surface. |
| `DELETE /students/invoices/{id}` | `routes/web.php:152` | Second delete path into `InvoiceController::destroy` — reverses wallet credits. |
| `GET/POST /schoolyear/setup-promotions`, `POST /schoolyear/update-promotion` | `routes/web.php:111-113` | Bulk student promotion between levels. Structural, destructive, unguarded. |
| `POST /absence/{student}/notify` | `routes/web.php:316` | Sends a WhatsApp message to a real parent's phone. Any authenticated user, any student, unrate-limited. |

The last one is an abuse vector the audit does not consider at all: a staff account can be used to
message arbitrary parents through the school's own WhatsApp number.

Note `routes/web.php:341` carries a comment that a *duplicate, unauthenticated* `DELETE
students/invoices/{id}` used to live below the auth group and was removed. Good fix — but the
surviving copy still has no role guard.

### 4.5 `[MEDIUM]` `[VERIFIED]` An ordinary invoice edit can claw a teacher's commission back to zero

`processTeacherPayment` applies a fallback when an offer defines no percentage for a subject
(`TeacherMembershipPaymentService.php:229-260`) — it distributes the unallocated remainder equally.
That fallback is what funds the teacher's original credit.

`updateExistingRecord`, which runs on **every subsequent edit of the same invoice**, recomputes the
percentage and does not apply the fallback:

```php
// line 550
$teacherPercentage = round((float)($offer->percentage[$record->teacher_subject] ?? 0), 2);
```

For a teacher whose share came from the fallback, this yields `0` → `$newTotalAmount = 0` →
`$walletDifference` is a large negative → the code debits their wallet by their entire prior
commission, on an edit that changed nothing about their share.

Compounding it: the parameter `$immediateWalletAmountFromCall` (line 541) holds the *correctly*
computed value from upstream and **is never referenced anywhere in the method body** — I confirmed
this by scanning lines 545-751. The method throws away the right answer and recomputes a wrong one.

### 4.6 `[MEDIUM]` `[VERIFIED-as-latent]` The ledger idempotency key cannot separate two subjects for one teacher

The unique key is `(teacher_id, invoice_id, month, reason)`. But
`TeacherMembershipPaymentService.php:340-348` documents that one teacher may legitimately appear twice
on a membership for two different subjects, each with its own payment record. Two subjects share one
membership, hence one invoice — so both credits produce the identical tuple. The second is rejected by
the constraint, caught inside `TeacherWalletService::move()`, logged as "already recorded", and
returns `false`. `createNewRecord` writes `total_paid_to_teacher` **without checking that return
value**, so the record claims a payment that never reached the wallet.

**I checked whether this is live. It is not — yet.** All seven memberships assign each teacher exactly
one subject:

```
485  [{"subject":"Math","teacherId":"8"}, {"subject":"PC","teacherId":"5"}]
```

The two records-per-teacher rows on membership 485 are the *same* subject across two *different*
invoices (843, 845), which the key handles correctly. So this is a real design gap that today's data
does not trigger. Nothing prevents it: `MembershipController::store` imposes no uniqueness on
`teacherId`.

I am rating this MEDIUM rather than CRITICAL specifically because I verified it is not currently
firing — my own PHP specialist rated it CRITICAL without checking the data.

### 4.7 `[MEDIUM]` `[VERIFIED]` `MembershipController` claims to reverse teacher payments and does not

`MembershipController.php:165-172`:

```php
if ($membership->payment_status === 'paid') {
    // Reverse old teacher payments
    $paymentService = new \App\Services\TeacherMembershipPaymentService();   // ← never used

    \App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
        ->where('is_active', true)
        ->update(['is_active' => false]);
}
```

The service is instantiated and never called. The comment says "Reverse old teacher payments"; the
code only flips `is_active` off. `destroy()` (lines 209-216) has the identical pattern. Swapping a
teacher on a paid membership leaves the departing teacher's credited money in their wallet with no
reconciliation. A dangling variable next to a comment describing the missing call is a strong signal a
reversal was intended and dropped.

### 4.8 `[LOW]` `[VERIFIED]` An unauthenticated file-serving route exists

`config/filesystems.php:36` sets `'serve' => true` on the `local` disk, which registers
`GET storage/{path}` with **middleware `[]`** — no auth, not even the `web` group. Confirmed in the
live route table.

I probed it from outside. It is currently harmless: the `local` disk root (`storage/app/private`)
holds nothing but `.gitignore`, and nginx returns 403 on the dotfile paths I tried. The
`payout-audit-baseline-2026-08-07.csv` sits in `storage/app/`, not the served root, and returns 404.

**But it is a live, unauthenticated read endpoint pointed at a directory the application can write
to.** The first `Storage::disk('local')->put(...)` of anything sensitive publishes it. Set
`'serve' => false`, or gate the route.

### 4.9 `[LOW]` `[VERIFIED]` The WhatsApp webhook is public, CSRF-exempt, and compares its secret unsafely

`POST /wasender/webhook` → `WasenderApi\Http\Controllers\WebhookController@handle`, middleware `[]`.
Not in `routes/web.php` — it is registered by the vendor package, which is why both the audit and a
`routes/` grep miss it. Live probe returns `400`, so the signature check is active.

The check itself (vendor `WebhookController::handle`):

```php
if (!$signature || !$secret || $signature !== $secret) {
    return response('Invalid signature', 400);
}
```

A plain `!==` on a shared secret, not an HMAC over the body and not `hash_equals` — so it is
byte-comparison timing-attackable and does not authenticate the payload, only the header. Every
accepted payload is dispatched as a Laravel event.

Low severity today because nothing in `app/` listens for those events (grep found no listeners), so a
forged webhook currently does nothing. Worth knowing before anyone writes the first listener.

### 4.10 `[LOW]` `[VERIFIED]` `.env` is world-readable

```
644 root:root /var/www/Tikoschool/.env
644 root:root /var/www/Tikoschool/docker-compose.yml
```

Practical risk today is near zero — the only non-root account on the box is `nobody`, and I confirmed
there are no other shell users. But this is the file holding `DB_ROOT_PASSWORD` and `REDIS_PASSWORD`,
and 0644 is the wrong default the moment a second account, a CI runner, or a less-privileged service
exists. `chmod 600`.

**Also:** `opencode` is now at **744 MB / 18.5%** of RAM, up from the 679 MB the audit recorded this
morning, and has been running 1h27m. It is growing, and it is still the largest single consumer on the
box — larger than MySQL.

---

## 5. Revised fix order

The audit's sequence is sound. Three changes: alerting moves up because it protects everything below
it, memory reclamation moves down because §3.1 shows there is no pressure, and the authorization step
widens from 5 routes to the real set.

| # | Action | Change from the audit | Effort |
|---|---|---|---|
| 01 | Close SSH: ufw, disable password auth + root login, fail2ban | unchanged | ~30 min |
| 02 | Nightly off-box DB backup with `--triggers` | unchanged | ~1 hr |
| 03 | **Wire up `wallet:check` failure alerting** | **new — was absent** | ~30 min |
| 04 | Re-throw `HttpExceptionInterface` ahead of the generic catch | was 04, unchanged | ~1 hr |
| 05 | Authorization across the **68** unguarded routes, prioritising the money/PII set in §4.4 | was "5 resources" | ~1-2 days |
| 06 | Route `TransactionController` wallet writes through `TeacherWalletService`; widen the ArchitectureTest regex | unchanged | ~2 hrs |
| 07 | `--queue=whatsapp,default` on the worker | unchanged | ~5 min |
| 08 | Fix `updateExistingRecord` percentage fallback (§4.5) | **new** | ~2 hrs |
| 09 | Dashboard N+1 + PHP-side pagination | was 07 | ~4 hrs |
| 10 | Drop `attendances_unique_full` (§4.3) + the four `membership_monthly_stats` duplicates | §4.3 is new | ~30 min |
| 11 | `sync_binlog` / `skip-log-bin`; `max_connections` 50→20 (live peak: **5**) | §4.2 is new | ~30 min |
| 12 | Reclaim resources: drop JIT, prune build cache, log rotation | was 08 — **demoted**, no memory pressure exists | ~1 hr |

---

## 6. Where I explicitly agree the audit is better than my agents

Worth recording, because the existing document did something my specialists did not.

**It verified against production and overruled its own agents twice.** Both corrections were correct:
`CACHE_STORE` really is `redis`, and the wallet drift really is latent. My performance agent made a
different but analogous error — reasoning about container memory from `ps` output rather than the
cgroup — and my PHP agent asserted nightly drift alerts were already firing without checking either
the data or the alerting path.

An agent reading source will reliably tell you what *could* be true. Only the server tells you what
*is*. The existing audit's `[VERIFIED]` / `[SOURCE]` distinction is the right discipline and I have
kept it here.

---

*Second audit performed 7 August 2026 against branch `local` at `8c68e33` and the live server on that
date. `[VERIFIED]` findings I reproduced myself on production.*
